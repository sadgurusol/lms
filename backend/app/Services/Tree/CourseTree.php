<?php

namespace App\Services\Tree;

use App\Models\Course;
use App\Models\CourseNode;
use App\Models\SchemaLevel;
use App\Support\FractionalIndex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Structural operations on a course's draft tree.
 *
 * Legality is enforced by database triggers; this class exists to compute sort
 * keys, rewrite subtree paths on a move, and turn trigger failures into
 * messages an author can act on.
 */
final class CourseTree
{
    /**
     * `$afterNodeId` expresses intent ("put it after this sibling"). The caller
     * never sends a sort key — the server derives it, which is what keeps two
     * concurrent drags from colliding.
     *
     * A null target is the *head* (before the first sibling). To add to the end,
     * which is what an author's "add" almost always means, call appendNode.
     */
    public function createNode(
        Course $course,
        SchemaLevel $level,
        string $title,
        ?CourseNode $parent = null,
        ?string $afterNodeId = null,
    ): CourseNode {
        return DB::transaction(function () use ($course, $level, $title, $parent, $afterNodeId) {
            $this->assertCapacity($course, $level, $parent);

            $node = CourseNode::create([
                'course_id' => $course->id,
                'parent_id' => $parent?->id,
                'schema_level_id' => $level->id,
                'title' => $title,
                'slug' => $this->uniqueSlug($course, $parent, $title),
                'sort_key' => $this->sortKeyAfter($course->id, $parent?->id, $afterNodeId),
            ]);

            $course->markDraftDiverged();

            // `path` and `depth` are assigned by the BEFORE INSERT trigger, so
            // the in-memory model does not have them until we read the row back.
            return $node->refresh();
        });
    }

    /**
     * Add a node to the end of its siblings — the natural meaning of "add".
     *
     * createNode with a null target head-inserts, which made every new Unit
     * jump above the last and the list look shuffled on reload. Appending after
     * the current last sibling is what an author expects.
     */
    public function appendNode(
        Course $course,
        SchemaLevel $level,
        string $title,
        ?CourseNode $parent = null,
    ): CourseNode {
        return $this->createNode(
            $course,
            $level,
            $title,
            $parent,
            $this->lastSiblingId($course->id, $parent?->id),
        );
    }

    /**
     * Move a node (and everything beneath it) under a new parent.
     *
     * The trigger revalidates the level nesting and rewrites the moved node's
     * own path. Its descendants are not touched by the trigger, so they are
     * rewritten here in one statement.
     */
    public function moveNode(CourseNode $node, ?CourseNode $newParent, ?string $afterNodeId = null): CourseNode
    {
        return DB::transaction(function () use ($node, $newParent, $afterNodeId) {
            if ($newParent !== null && $this->wouldCreateCycle($node, $newParent)) {
                throw new RuntimeException('A node cannot be moved beneath itself.');
            }

            $oldPath = $node->path;

            $node->update([
                'parent_id' => $newParent?->id,
                'sort_key' => $this->sortKeyAfter($node->course_id, $newParent?->id, $afterNodeId, $node->id),
            ]);

            $node->refresh();

            // Reparent the subtree: strip the old ancestor path, graft on the new.
            DB::update(<<<'SQL'
                UPDATE course_nodes
                   SET path  = ?::ltree || subpath(path, nlevel(?::ltree)),
                       depth = nlevel(?::ltree) + nlevel(path) - nlevel(?::ltree) - 1
                 WHERE path <@ ?::ltree
                   AND id <> ?
            SQL, [$node->path, $oldPath, $node->path, $oldPath, $oldPath, $node->id]);

            $this->courseOf($node)->markDraftDiverged();

            return $node;
        });
    }

    public function reorderNode(CourseNode $node, ?string $afterNodeId): CourseNode
    {
        $node->update([
            'sort_key' => $this->sortKeyAfter($node->course_id, $node->parent_id, $afterNodeId, $node->id),
        ]);

        $this->courseOf($node)->markDraftDiverged();

        return $node;
    }

    /**
     * Retitle a node. The slug is *not* recomputed: it is part of the node's
     * identity, may already be referenced by a deep link, and a rename is
     * usually a typo fix. Slugs are assigned once, at creation.
     */
    public function renameNode(CourseNode $node, string $title): CourseNode
    {
        $node->update(['title' => $title]);

        $this->courseOf($node)->markDraftDiverged();

        return $node;
    }

    /**
     * Remove a node and everything beneath it.
     *
     * The parent_id foreign key cascades on *hard* delete only, so a soft delete
     * would orphan the subtree — its rows would linger, still matching the
     * partial unique indexes, and block a later node from taking the freed slug
     * or sort key. So the whole subtree is soft-deleted in one statement.
     */
    public function deleteNode(CourseNode $node): void
    {
        DB::transaction(function () use ($node) {
            $course = $this->courseOf($node);

            CourseNode::query()
                ->whereRaw('path <@ ?::ltree', [$node->path])
                ->update(['deleted_at' => now()]);

            $course->markDraftDiverged();
        });
    }

    /**
     * max_occurrences is hard-enforced on create: the editor should never have
     * offered the button. min_occurrences is *not* checked here — an author must
     * be able to create an empty Unit and then fill it. That is a publish-gate
     * concern (I12).
     */
    private function assertCapacity(Course $course, SchemaLevel $level, ?CourseNode $parent): void
    {
        if ($level->max_occurrences === null) {
            return;
        }

        $siblings = CourseNode::where('course_id', $course->id)
            ->where('parent_id', $parent?->id)
            ->where('schema_level_id', $level->id)
            ->count();

        if ($siblings >= $level->max_occurrences) {
            throw new RuntimeException(
                "A {$parent?->schemaLevel->name} may hold at most {$level->max_occurrences} {$level->plural_name}."
            );
        }
    }

    private function wouldCreateCycle(CourseNode $node, CourseNode $newParent): bool
    {
        return $newParent->id === $node->id
            || DB::selectOne(
                'SELECT 1 AS hit FROM course_nodes WHERE id = ? AND path <@ ?::ltree',
                [$newParent->id, $node->path],
            ) !== null;
    }

    /** The id of the last sibling under $parentId, or null when there are none. */
    private function lastSiblingId(string $courseId, ?string $parentId): ?string
    {
        return CourseNode::query()
            ->where('course_id', $courseId)
            ->where('parent_id', $parentId)
            // sort_key is COLLATE "C", so this orders byte-wise, as the index does.
            ->orderByDesc('sort_key')
            ->value('id');
    }

    private function sortKeyAfter(
        string $courseId,
        ?string $parentId,
        ?string $afterNodeId,
        ?string $excludeNodeId = null,
    ): string {
        $siblings = CourseNode::query()
            ->where('course_id', $courseId)
            ->where('parent_id', $parentId)
            ->when($excludeNodeId, fn ($q) => $q->whereKeyNot($excludeNodeId))
            ->orderBy('sort_key')
            ->pluck('sort_key', 'id');

        if ($afterNodeId === null) {
            return FractionalIndex::between(null, $siblings->first());
        }

        $keys = $siblings->values()->all();
        $ids = $siblings->keys()->all();
        $index = array_search($afterNodeId, $ids, true);

        if ($index === false) {
            throw new RuntimeException("Node {$afterNodeId} is not a sibling at this position.");
        }

        return FractionalIndex::between($keys[$index], $keys[$index + 1] ?? null);
    }

    /**
     * Explicit, not `$node->course`.
     *
     * A lazy-loaded relation inside a tree operation is an N+1 waiting for a
     * loop, and `Model::shouldBeStrict()` refuses it outside production — which
     * is how this was found.
     */
    private function courseOf(CourseNode $node): Course
    {
        return $node->relationLoaded('course')
            ? $node->course
            : Course::findOrFail($node->course_id);
    }

    private function uniqueSlug(Course $course, ?CourseNode $parent, string $title): string
    {
        $base = Str::slug($title) ?: 'node';
        $slug = $base;
        $suffix = 2;

        while (CourseNode::where('course_id', $course->id)
            ->where('parent_id', $parent?->id)
            ->where('slug', $slug)
            ->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
