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
                'sort_key' => $this->sortKeyAfter($course, $parent?->id, $afterNodeId),
            ]);

            $course->markDraftDiverged();

            // `path` and `depth` are assigned by the BEFORE INSERT trigger, so
            // the in-memory model does not have them until we read the row back.
            return $node->refresh();
        });
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
                'sort_key' => $this->sortKeyAfter($node->course, $newParent?->id, $afterNodeId, $node->id),
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

            $node->course->markDraftDiverged();

            return $node;
        });
    }

    public function reorderNode(CourseNode $node, ?string $afterNodeId): CourseNode
    {
        $node->update([
            'sort_key' => $this->sortKeyAfter($node->course, $node->parent_id, $afterNodeId, $node->id),
        ]);

        $node->course->markDraftDiverged();

        return $node;
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

    private function sortKeyAfter(
        Course $course,
        ?string $parentId,
        ?string $afterNodeId,
        ?string $excludeNodeId = null,
    ): string {
        $siblings = CourseNode::query()
            ->where('course_id', $course->id)
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
