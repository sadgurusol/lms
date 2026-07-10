<?php

use App\Models\CourseNode;
use App\Services\Tree\CourseTree;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\ExpectationFailedException;

/*
 * Randomised create / move / reorder / delete sequences against the course tree.
 *
 * The structural invariants are enforced by database triggers, so they must hold
 * after *every* operation, whatever order the operations arrive in. A tree that
 * corrupts silently is unrecoverable six months later; this is the test that
 * catches it now.
 *
 * Seeded, so a failure is reproducible: the seed appears in every failure message.
 */

/**
 * Deterministic pick.
 *
 * Not Collection::random() — that draws from random_int(), a CSPRNG which
 * mt_srand() does not seed. A property test whose failures cannot be replayed
 * from their seed is a flake generator, not a test.
 *
 * @template T
 *
 * @param  Enumerable<array-key, T>|array<array-key, T>  $items
 * @return T
 */
function pick(Enumerable|array $items): mixed
{
    $values = array_values($items instanceof Enumerable ? $items->all() : $items);

    return $values[mt_rand(0, count($values) - 1)];
}

/** Assert I2, I3, I7, I8 across the whole tree. */
function assertTreeInvariants(string $courseId, string $context): void
{
    // I8 — path and depth agree with parentage, for every node.
    $wrongPath = DB::selectOne(<<<'SQL'
        SELECT c.id
          FROM course_nodes c
          LEFT JOIN course_nodes p ON p.id = c.parent_id
         WHERE c.course_id = ?
           AND c.deleted_at IS NULL
           AND (
                (c.parent_id IS NULL     AND c.path <> text2ltree(replace(c.id::text, '-', '')))
             OR (c.parent_id IS NOT NULL AND c.path <> p.path || text2ltree(replace(c.id::text, '-', '')))
           )
         LIMIT 1
    SQL, [$courseId]);

    expect($wrongPath)->toBeNull("{$context}: a node's path does not derive from its parent's");

    $wrongDepth = DB::selectOne(<<<'SQL'
        SELECT id FROM course_nodes
         WHERE course_id = ? AND deleted_at IS NULL AND depth <> nlevel(path) - 1
         LIMIT 1
    SQL, [$courseId]);

    expect($wrongDepth)->toBeNull("{$context}: depth disagrees with path length");

    // I2 + I3 — every node's level is a declared child of its parent's level,
    // and root nodes hold root levels.
    $illegalNesting = DB::selectOne(<<<'SQL'
        SELECT c.id
          FROM course_nodes c
          JOIN schema_levels  sl ON sl.id = c.schema_level_id
          LEFT JOIN course_nodes p ON p.id = c.parent_id
         WHERE c.course_id = ?
           AND c.deleted_at IS NULL
           AND (
                (c.parent_id IS NULL     AND sl.parent_level_id IS NOT NULL)
             OR (c.parent_id IS NOT NULL AND sl.parent_level_id IS DISTINCT FROM p.schema_level_id)
           )
         LIMIT 1
    SQL, [$courseId]);

    expect($illegalNesting)->toBeNull("{$context}: a node is nested under a level that does not permit it");

    // I7 — sibling sort keys are unique, and ordering by sort_key is a total order.
    $duplicateKeys = DB::selectOne(<<<'SQL'
        SELECT count(*) AS hits FROM (
            SELECT parent_id, sort_key
              FROM course_nodes
             WHERE course_id = ? AND deleted_at IS NULL
             GROUP BY parent_id, sort_key
            HAVING count(*) > 1
        ) dupes
    SQL, [$courseId]);

    expect((int) $duplicateKeys->hits)->toBe(0, "{$context}: duplicate sibling sort keys");

    // No orphans: every non-root node's parent still exists and is alive.
    $orphans = DB::selectOne(<<<'SQL'
        SELECT c.id FROM course_nodes c
         WHERE c.course_id = ? AND c.deleted_at IS NULL AND c.parent_id IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM course_nodes p WHERE p.id = c.parent_id AND p.deleted_at IS NULL)
         LIMIT 1
    SQL, [$courseId]);

    expect($orphans)->toBeNull("{$context}: a live node has a dead or missing parent");
}

it('holds every structural invariant under random create, move, reorder and delete', function () {
    $seed = (int) (getenv('TREE_SEED') ?: 20260710);
    mt_srand($seed);

    [$course, $partLevel, $chapterLevel, $topicLevel] = textbookCourse();
    $tree = app(CourseTree::class);

    $levelOf = [
        $partLevel->id => $partLevel,
        $chapterLevel->id => $chapterLevel,
        $topicLevel->id => $topicLevel,
    ];

    // The level a child of a node at this level must take. Absent => leaf.
    // Keyed only by real level ids: a null key would silently become '' in PHP.
    $childLevelOf = [
        $partLevel->id => $chapterLevel,
        $chapterLevel->id => $topicLevel,
    ];

    $operations = 0;

    for ($step = 0; $step < 250; $step++) {
        $live = CourseNode::where('course_id', $course->id)->get();
        $action = $live->isEmpty() ? 'create' : ['create', 'create', 'move', 'reorder', 'delete'][mt_rand(0, 4)];

        try {
            if ($action === 'create') {
                // Pick a parent that can legally take a child, or the course root.
                $candidates = $live->filter(fn (CourseNode $n) => isset($childLevelOf[$n->schema_level_id]));
                $parent = $candidates->isNotEmpty() && mt_rand(0, 3) > 0 ? pick($candidates) : null;
                $level = $parent === null ? $partLevel : $childLevelOf[$parent->schema_level_id];

                $siblings = CourseNode::where('course_id', $course->id)
                    ->where('parent_id', $parent?->id)->pluck('id')->all();

                $tree->createNode(
                    $course, $level, 'Node '.mt_rand(1000, 9999), $parent,
                    afterNodeId: $siblings !== [] && mt_rand(0, 1) === 1 ? pick($siblings) : null,
                );
                $operations++;
            } elseif ($action === 'move') {
                $node = pick($live);
                $level = $levelOf[$node->schema_level_id];

                // Only ever move a node under a parent whose level declares it.
                $targets = $level->parent_level_id === null
                    ? [null]
                    : $live->where('schema_level_id', $level->parent_level_id)->all();

                if ($targets !== []) {
                    $target = pick($targets);

                    // Skip moves into the node's own subtree — illegal by design,
                    // and covered by its own example test.
                    if ($target === null || ! str_starts_with((string) $target->path, (string) $node->path)) {
                        $tree->moveNode($node->fresh(), $target?->fresh());
                        $operations++;
                    }
                }
            } elseif ($action === 'reorder') {
                $node = pick($live);
                $siblings = CourseNode::where('course_id', $course->id)
                    ->where('parent_id', $node->parent_id)
                    ->whereKeyNot($node->id)
                    ->pluck('id')->all();

                $tree->reorderNode($node->fresh(), $siblings !== [] ? pick($siblings) : null);
                $operations++;
            } else {
                $node = pick($live);
                // Soft-delete cascades to descendants: a live child of a dead
                // parent is an orphan, which assertTreeInvariants rejects.
                CourseNode::whereRaw('path <@ ?::ltree', [$node->path])->delete();
                $operations++;
            }
        } catch (RuntimeException $e) {
            // Capacity limits and cycle guards are expected refusals, not bugs.
            if (! str_contains($e->getMessage(), 'at most')
                && ! str_contains($e->getMessage(), 'beneath itself')) {
                throw $e;
            }
        }

        assertTreeInvariants($course->id, "seed {$seed}, step {$step}, action {$action}");
    }

    expect($operations)->toBeGreaterThan(100)
        ->and(CourseNode::where('course_id', $course->id)->count())->toBeGreaterThan(0);
});

/**
 * A property test that cannot fail is worse than no test. These three assert the
 * checker above actually detects the corruption it claims to.
 *
 * `path` and `depth` can be corrupted directly because the structure trigger
 * fires on UPDATE OF parent_id, schema_level_id — not on a raw path write. That
 * is precisely the kind of back-door write the checker exists to catch.
 */
it('detects a corrupted path', function () {
    [$course, $partLevel, $chapterLevel] = textbookCourse();
    $tree = app(CourseTree::class);

    $part = $tree->createNode($course, $partLevel, 'Part');
    $chapter = $tree->createNode($course, $chapterLevel, 'Chapter', $part);

    DB::update("UPDATE course_nodes SET path = 'deadbeef' WHERE id = ?", [$chapter->id]);

    expect(fn () => assertTreeInvariants($course->id, 'corrupted'))
        ->toThrow(ExpectationFailedException::class, 'does not derive from');
});

it('detects a corrupted depth', function () {
    [$course, $partLevel] = textbookCourse();
    $part = app(CourseTree::class)->createNode($course, $partLevel, 'Part');

    DB::update('UPDATE course_nodes SET depth = 7 WHERE id = ?', [$part->id]);

    expect(fn () => assertTreeInvariants($course->id, 'corrupted'))
        ->toThrow(ExpectationFailedException::class, 'depth disagrees');
});

it('detects a duplicate sibling sort key', function () {
    [$course, $partLevel] = textbookCourse();
    $tree = app(CourseTree::class);

    $a = $tree->createNode($course, $partLevel, 'A');
    $b = $tree->createNode($course, $partLevel, 'B', null, afterNodeId: $a->id);

    // The partial unique index blocks this through normal writes, so the
    // corruption has to be smuggled in with the index dropped.
    DB::statement('DROP INDEX course_nodes_sibling_order_root');
    DB::update('UPDATE course_nodes SET sort_key = ? WHERE id = ?', [$a->sort_key, $b->id]);

    expect(fn () => assertTreeInvariants($course->id, 'corrupted'))
        ->toThrow(ExpectationFailedException::class, 'duplicate sibling sort keys');
});

it('detects an orphaned node', function () {
    [$course, $partLevel, $chapterLevel] = textbookCourse();
    $tree = app(CourseTree::class);

    $part = $tree->createNode($course, $partLevel, 'Part');
    $tree->createNode($course, $chapterLevel, 'Chapter', $part);

    // Soft-delete the parent alone, leaving its child alive.
    DB::update('UPDATE course_nodes SET deleted_at = now() WHERE id = ?', [$part->id]);

    expect(fn () => assertTreeInvariants($course->id, 'corrupted'))
        ->toThrow(ExpectationFailedException::class, 'dead or missing parent');
});

it('keeps sibling ordering consistent between sort_key and insertion intent', function () {
    mt_srand(99);
    [$course, $partLevel] = textbookCourse();
    $tree = app(CourseTree::class);

    /** @var list<string> $expected */
    $expected = [];

    for ($i = 0; $i < 60; $i++) {
        $at = mt_rand(0, count($expected));
        $after = $expected[$at - 1] ?? null;   // $at === 0 => insert at head

        $node = $tree->createNode($course, $partLevel, "N{$i}", null, afterNodeId: $after);
        array_splice($expected, $at, 0, [$node->id]);
    }

    $actual = $course->rootNodes()->pluck('id')->all();

    expect($actual)->toBe($expected);
});
