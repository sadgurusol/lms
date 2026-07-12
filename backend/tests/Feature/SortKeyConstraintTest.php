<?php

use App\Models\ContentBlock;
use App\Models\SchemaLevel;
use App\Models\SchemaVersion;
use App\Services\Tree\CourseTree;
use App\Support\FractionalIndex;

/**
 * A sort key that ends in "0" is accepted by every insert, sorts plausibly, and
 * sits harmless until someone appends a sibling after it — because "X0" and "X"
 * denote the same fraction, so no key lies strictly between "X0" and its
 * successor, and FractionalIndex::midpoint() throws. That is a long fuse, and it
 * burned for eleven milestones inside the factories.
 *
 * The database now refuses to store one. See the 180000 migration.
 */
it('refuses a sort key that ends in zero', function () {
    $version = SchemaVersion::factory()->create();

    expectDatabaseRejection(
        fn () => SchemaLevel::factory()->create([
            'schema_version_id' => $version->id,
            'sort_key' => 'a0',
        ]),
        'schema_levels_sort_key_is_fractional',
    );
});

it('refuses a sort key outside the base-62 digit set', function () {
    $version = SchemaVersion::factory()->create();

    foreach (['a-b', 'a b', 'a.b', ''] as $key) {
        expectDatabaseRejection(
            fn () => SchemaLevel::factory()->create([
                'schema_version_id' => $version->id,
                'sort_key' => $key,
            ]),
            'schema_levels_sort_key_is_fractional',
        );
    }
});

/** The constraint mirrors FractionalIndex::isValid(); it must not reject its output. */
it('accepts every key the generator produces', function () {
    $version = SchemaVersion::factory()->create();
    $keys = FractionalIndex::sequence(30);

    // Squeeze keys in between too — those are the long, exotic ones.
    $keys[] = FractionalIndex::between($keys[0], $keys[1]);
    $keys[] = FractionalIndex::between(null, $keys[0]);

    foreach ($keys as $key) {
        expect(FractionalIndex::isValid($key))->toBeTrue();

        SchemaLevel::factory()->create([
            'schema_version_id' => $version->id,
            'name' => "Level {$key}",
            'sort_key' => $key,
        ]);
    }

    expect(SchemaLevel::where('schema_version_id', $version->id)->count())->toBe(count($keys));
});

/** The same rule holds on the other four sort_key columns. */
it('constrains content block sort keys', function () {
    [$course, $partLevel, $chapterLevel] = textbookCourse();
    $tree = app(CourseTree::class);

    $part = $tree->createNode($course, $partLevel, 'Part One');
    // Chapter is the shallowest level that permits rich_text.
    $chapter = $tree->createNode($course, $chapterLevel, 'Chapter One', $part);

    expectDatabaseRejection(
        fn () => ContentBlock::create([
            'course_node_id' => $chapter->id,
            'type' => 'rich_text',
            'sort_key' => 'V0',
            'payload' => ['format' => 'portable_text', 'body' => []],
        ]),
        'content_blocks_sort_key_is_fractional',
    );
});
