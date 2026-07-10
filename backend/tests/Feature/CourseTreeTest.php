<?php

use App\Models\ContentBlock;
use App\Models\Course;
use App\Models\CourseNode;
use App\Models\Media;
use App\Services\Tree\CourseTree;
use App\Support\FractionalIndex;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    [$this->course, $this->part, $this->chapter, $this->topic] = textbookCourse();
    $this->tree = app(CourseTree::class);
});

/*
|--------------------------------------------------------------------------
| I3 — a node's level must be a declared child of its parent's level
|--------------------------------------------------------------------------
*/

it('derives path and depth from the parent, not from the caller', function () {
    $part = $this->tree->createNode($this->course, $this->part, 'Part One');
    $chapter = $this->tree->createNode($this->course, $this->chapter, 'Chapter One', $part);
    $topic = $this->tree->createNode($this->course, $this->topic, 'Topic One', $chapter);

    $hex = fn (CourseNode $n) => str_replace('-', '', $n->id);

    expect($part->path)->toBe($hex($part))
        ->and($chapter->path)->toBe($hex($part).'.'.$hex($chapter))
        ->and($topic->path)->toBe($hex($part).'.'.$hex($chapter).'.'.$hex($topic))
        ->and([$part->depth, $chapter->depth, $topic->depth])->toBe([0, 1, 2]);
});

it('refuses a Topic nested directly under a Part', function () {
    $part = $this->tree->createNode($this->course, $this->part, 'Part One');

    expectDatabaseRejection(
        fn () => $this->tree->createNode($this->course, $this->topic, 'Orphan Topic', $part),
        'may not nest under a node of level',
    );
});

it('refuses a Chapter at the root of the course', function () {
    expectDatabaseRejection(
        fn () => $this->tree->createNode($this->course, $this->chapter, 'Rootless Chapter'),
        'requires a parent node',
    );
});

it('refuses a Part nested under a Part', function () {
    $part = $this->tree->createNode($this->course, $this->part, 'Part One');

    expectDatabaseRejection(
        fn () => $this->tree->createNode($this->course, $this->part, 'Nested Part', $part),
        'is a root level and cannot be nested',
    );
});

/*
|--------------------------------------------------------------------------
| I4 — a node's level must belong to the course's bound schema version
|--------------------------------------------------------------------------
*/

it('refuses a level borrowed from another schema version', function () {
    $otherVersion = publish(textbookSchema());
    $foreignPart = $otherVersion->levels()->where('name', 'Part')->firstOrFail();

    expectDatabaseRejection(
        fn () => $this->tree->createNode($this->course, $foreignPart, 'Smuggled Part'),
        'does not belong to the schema version bound to course',
    );
});

it('refuses a parent node from a different course', function () {
    $otherCourse = Course::factory()->onSchema($this->course->schemaVersion)->create();
    $foreignPart = $this->tree->createNode($otherCourse, $this->part, 'Their Part');

    expectDatabaseRejection(
        fn () => $this->tree->createNode($this->course, $this->chapter, 'Cross-course Chapter', $foreignPart),
        'belongs to a different course',
    );
});

/*
|--------------------------------------------------------------------------
| I1 — a course cannot be rebound to a different schema version
|--------------------------------------------------------------------------
*/

it('pins the schema version once a node exists', function () {
    $other = publish(textbookSchema());

    // Before any nodes: rebinding is allowed.
    $this->course->update(['schema_version_id' => $other->id]);
    expect($this->course->fresh()->schema_version_id)->toBe($other->id);

    $level = $other->levels()->where('name', 'Part')->firstOrFail();
    $this->tree->createNode($this->course->fresh(), $level, 'Part One');

    $yetAnother = publish(textbookSchema());

    expectDatabaseRejection(
        fn () => $this->course->fresh()->update(['schema_version_id' => $yetAnother->id]),
        'its schema version cannot be changed',
    );
});

/*
|--------------------------------------------------------------------------
| I5 — a block's type must be permitted by its node's level
|--------------------------------------------------------------------------
*/

it('permits a rich_text block on a Chapter', function () {
    $part = $this->tree->createNode($this->course, $this->part, 'Part One');
    $chapter = $this->tree->createNode($this->course, $this->chapter, 'Chapter One', $part);

    $block = ContentBlock::create([
        'course_node_id' => $chapter->id,
        'type' => ContentBlock::TYPE_RICH_TEXT,
        'sort_key' => FractionalIndex::between(null, null),
        'payload' => ['format' => 'portable_text', 'body' => []],
    ]);

    expect($block->exists)->toBeTrue();
});

it('refuses a video block on a Chapter, which permits only rich_text and callout', function () {
    $part = $this->tree->createNode($this->course, $this->part, 'Part One');
    $chapter = $this->tree->createNode($this->course, $this->chapter, 'Chapter One', $part);

    $video = Media::factory()->video()->create();

    // A valid payload, so it is the level trigger that rejects this — not the
    // payload validator standing in front of it.
    expectDatabaseRejection(
        fn () => ContentBlock::create([
            'course_node_id' => $chapter->id,
            'type' => ContentBlock::TYPE_VIDEO,
            'sort_key' => FractionalIndex::between(null, null),
            'media_id' => $video->id,
            'payload' => ['media_id' => $video->id],
        ]),
        'is not permitted on the level of node',
    );
});

it('refuses any block on a Part, which allows no content at all', function () {
    $part = $this->tree->createNode($this->course, $this->part, 'Part One');

    expectDatabaseRejection(
        fn () => ContentBlock::create([
            'course_node_id' => $part->id,
            'type' => ContentBlock::TYPE_RICH_TEXT,
            'sort_key' => FractionalIndex::between(null, null),
            'payload' => ['format' => 'portable_text', 'body' => []],
        ]),
        'is not permitted on the level of node',
    );
});

it('permits a video block on a Topic', function () {
    $part = $this->tree->createNode($this->course, $this->part, 'Part One');
    $chapter = $this->tree->createNode($this->course, $this->chapter, 'Chapter One', $part);
    $topic = $this->tree->createNode($this->course, $this->topic, 'Topic One', $chapter);

    expect($topic->permitsBlockType(ContentBlock::TYPE_VIDEO))->toBeTrue();

    $video = Media::factory()->video()->create();

    ContentBlock::create([
        'course_node_id' => $topic->id,
        'type' => ContentBlock::TYPE_VIDEO,
        'sort_key' => FractionalIndex::between(null, null),
        'media_id' => $video->id,
        'payload' => ['media_id' => $video->id, 'duration_s' => $video->duration_s],
    ]);

    expect($topic->blocks()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Cardinality
|--------------------------------------------------------------------------
*/

it('hard-enforces max_occurrences on create', function () {
    $small = publish(tap(textbookSchema(), function ($version) {
        $version->levels()->where('name', 'Topic')->firstOrFail()->update(['max_occurrences' => 2]);
    }));

    $course = Course::factory()->onSchema($small)->create();
    $part = $this->tree->createNode($course, $small->levels()->where('name', 'Part')->firstOrFail(), 'P');
    $chapter = $this->tree->createNode($course, $small->levels()->where('name', 'Chapter')->firstOrFail(), 'C', $part);
    $topicLevel = $small->levels()->where('name', 'Topic')->firstOrFail();

    $this->tree->createNode($course, $topicLevel, 'T1', $chapter);
    $this->tree->createNode($course, $topicLevel, 'T2', $chapter);

    expect(fn () => $this->tree->createNode($course, $topicLevel, 'T3', $chapter))
        ->toThrow(RuntimeException::class, 'at most 2 Topics');
});

it('allows an empty Part, because min_occurrences is a publish-gate concern', function () {
    $part = $this->tree->createNode($this->course, $this->part, 'Empty Part');

    expect($part->children()->count())->toBe(0)
        ->and($this->part->min_occurrences)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| allowed-children: what drives the editor
|--------------------------------------------------------------------------
*/

it('reports the levels a node may take as children, with remaining capacity', function () {
    $part = $this->tree->createNode($this->course, $this->part, 'Part One');
    $chapter = $this->tree->createNode($this->course, $this->chapter, 'Chapter One', $part);

    expect($this->course->allowedRootLevels()->pluck('name')->all())->toBe(['Part'])
        ->and(array_column($part->allowedChildLevels(), 'name'))->toBe(['Chapter'])
        ->and($part->allowedChildLevels()[0]['remaining'])->toBeNull();

    $this->tree->createNode($this->course, $this->topic, 'T1', $chapter);

    $allowed = $chapter->fresh()->allowedChildLevels()[0];

    expect($allowed['name'])->toBe('Topic')
        ->and($allowed['plural_name'])->toBe('Topics')
        ->and($allowed['remaining'])->toBe(39);   // 40 max, 1 used
});

it('reports no allowed children for a leaf level', function () {
    $part = $this->tree->createNode($this->course, $this->part, 'Part One');
    $chapter = $this->tree->createNode($this->course, $this->chapter, 'Chapter One', $part);
    $topic = $this->tree->createNode($this->course, $this->topic, 'Topic One', $chapter);

    expect($topic->allowedChildLevels())->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Ordering (I7) and moves (I8)
|--------------------------------------------------------------------------
*/

it('orders siblings by fractional sort key without renumbering', function () {
    $a = $this->tree->createNode($this->course, $this->part, 'A');
    $c = $this->tree->createNode($this->course, $this->part, 'C', null, afterNodeId: $a->id);
    $b = $this->tree->createNode($this->course, $this->part, 'B', null, afterNodeId: $a->id);

    $order = $this->course->rootNodes()->pluck('title')->all();

    expect($order)->toBe(['A', 'B', 'C'])
        // A and C keep the keys they were born with: inserting B touched one row.
        ->and($a->fresh()->sort_key)->toBe($a->sort_key)
        ->and($c->fresh()->sort_key)->toBe($c->sort_key);
});

it('sorts sort keys byte-wise, not by locale', function () {
    // Under a locale-aware collation "a" sorts before "B" and ordering collapses.
    // COLLATE "C" is what keeps base-62 keys ordering correctly in Postgres.
    $collation = DB::selectOne("
        SELECT collname FROM pg_collation c
        JOIN pg_attribute a ON a.attcollation = c.oid
        JOIN pg_class t ON t.oid = a.attrelid
        WHERE t.relname = 'course_nodes' AND a.attname = 'sort_key'
    ");

    expect($collation->collname)->toBe('C');
});

it('rewrites the whole subtree on a move', function () {
    $part1 = $this->tree->createNode($this->course, $this->part, 'Part One');
    $part2 = $this->tree->createNode($this->course, $this->part, 'Part Two', null, afterNodeId: $part1->id);

    $chapter = $this->tree->createNode($this->course, $this->chapter, 'Chapter', $part1);
    $topicA = $this->tree->createNode($this->course, $this->topic, 'Topic A', $chapter);
    $topicB = $this->tree->createNode($this->course, $this->topic, 'Topic B', $chapter, afterNodeId: $topicA->id);

    $this->tree->moveNode($chapter, $part2);

    $chapter->refresh();
    $topicA->refresh();
    $topicB->refresh();

    $hex = fn (CourseNode $n) => str_replace('-', '', $n->id);

    expect($chapter->parent_id)->toBe($part2->id)
        ->and($chapter->depth)->toBe(1)
        ->and($chapter->path)->toBe($hex($part2).'.'.$hex($chapter))
        ->and($topicA->depth)->toBe(2)
        ->and($topicA->path)->toBe($hex($part2).'.'.$hex($chapter).'.'.$hex($topicA))
        ->and($topicB->path)->toBe($hex($part2).'.'.$hex($chapter).'.'.$hex($topicB))
        ->and($part1->fresh()->children()->count())->toBe(0)
        ->and($part2->fresh()->children()->count())->toBe(1);
});

it('refuses a move that would nest a node beneath itself', function () {
    $part = $this->tree->createNode($this->course, $this->part, 'Part One');
    $chapter = $this->tree->createNode($this->course, $this->chapter, 'Chapter', $part);
    $topic = $this->tree->createNode($this->course, $this->topic, 'Topic', $chapter);

    expect(fn () => $this->tree->moveNode($chapter, $topic))
        ->toThrow(RuntimeException::class, 'beneath itself');
});

it('refuses a move to a parent whose level does not permit the node', function () {
    $part1 = $this->tree->createNode($this->course, $this->part, 'Part One');
    $part2 = $this->tree->createNode($this->course, $this->part, 'Part Two', null, afterNodeId: $part1->id);
    $chapter = $this->tree->createNode($this->course, $this->chapter, 'Chapter', $part1);
    $topic = $this->tree->createNode($this->course, $this->topic, 'Topic', $chapter);

    // A Topic belongs under a Chapter, never directly under a Part.
    expectDatabaseRejection(
        fn () => $this->tree->moveNode($topic, $part2),
        'may not nest under a node of level',
    );
});

it('finds descendants through the ltree path in one query', function () {
    $part = $this->tree->createNode($this->course, $this->part, 'Part One');
    $chapter = $this->tree->createNode($this->course, $this->chapter, 'Chapter', $part);
    $this->tree->createNode($this->course, $this->topic, 'Topic A', $chapter);
    $this->tree->createNode($this->course, $this->topic, 'Topic B', $chapter);

    expect($part->descendants()->pluck('title')->all())
        ->toBe(['Chapter', 'Topic A', 'Topic B']);
});

it('generates unique slugs among siblings', function () {
    $part = $this->tree->createNode($this->course, $this->part, 'Introduction');
    $chapter1 = $this->tree->createNode($this->course, $this->chapter, 'Overview', $part);
    $chapter2 = $this->tree->createNode($this->course, $this->chapter, 'Overview', $part, afterNodeId: $chapter1->id);

    expect($chapter1->slug)->toBe('overview')
        ->and($chapter2->slug)->toBe('overview-2');
});
