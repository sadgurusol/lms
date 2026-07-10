<?php

use App\Models\SchemaLevel;
use App\Models\SchemaVersion;
use App\Services\Schemas\CloneSchemaVersion;
use Illuminate\Support\Facades\DB;

it('builds a three level schema and publishes it', function () {
    $version = publish(textbookSchema());

    expect($version->isPublished())->toBeTrue()
        ->and($version->published_at)->not->toBeNull()
        ->and($version->levels)->toHaveCount(3)
        ->and($version->rootLevels()->get())->toHaveCount(1);
});

it('lets a chapter carry content while still having children', function () {
    $version = textbookSchema();
    $chapter = $version->levels()->where('name', 'Chapter')->firstOrFail();

    expect($chapter->allows_content)->toBeTrue()
        ->and($chapter->isLeaf())->toBeFalse()
        ->and($chapter->permitsBlockType('rich_text'))->toBeTrue()
        ->and($chapter->permitsBlockType('video'))->toBeFalse();
});

it('refuses to insert a level into a published version', function () {
    $version = publish(textbookSchema());

    expectDatabaseRejection(
        fn () => SchemaLevel::factory()->create(['schema_version_id' => $version->id]),
        'cannot be modified',
    );
});

it('refuses to update a level in a published version', function () {
    $version = publish(textbookSchema());
    $level = $version->levels()->first();

    expectDatabaseRejection(
        fn () => DB::table('schema_levels')->where('id', $level->id)->update(['name' => 'Module']),
        'cannot be modified',
    );
});

it('refuses to delete a level from a published version', function () {
    $version = publish(textbookSchema());
    $level = $version->levels()->where('name', 'Topic')->firstOrFail();

    expectDatabaseRejection(
        fn () => DB::table('schema_levels')->where('id', $level->id)->delete(),
        'cannot be modified',
    );
});

it('refuses to delete a published version, cascading its levels away', function () {
    $version = publish(textbookSchema());

    expectDatabaseRejection(
        fn () => DB::table('schema_versions')->where('id', $version->id)->delete(),
        'cannot be deleted',
    );

    expect(SchemaLevel::where('schema_version_id', $version->id)->count())->toBe(3);
});

it('allows levels to be edited freely while the version is a draft', function () {
    $version = textbookSchema();
    $level = $version->levels()->where('name', 'Topic')->firstOrFail();

    $level->update(['name' => 'Subtopic', 'max_occurrences' => 50]);
    expect($level->fresh()->name)->toBe('Subtopic');

    $level->delete();
    expect($version->levels()->count())->toBe(2);
});

it('refuses to unpublish a version', function () {
    $version = publish(textbookSchema());

    expectDatabaseRejection(
        fn () => DB::table('schema_versions')->where('id', $version->id)
            ->update(['status' => SchemaVersion::STATUS_DRAFT]),
        'cannot be modified',
    );
});

it('allows a published version to be archived', function () {
    $version = publish(textbookSchema());

    DB::table('schema_versions')->where('id', $version->id)
        ->update(['status' => SchemaVersion::STATUS_ARCHIVED]);

    expect($version->fresh()->status)->toBe(SchemaVersion::STATUS_ARCHIVED);
});

it('discards a draft version with its levels', function () {
    $version = textbookSchema();

    $version->delete();

    expect(SchemaVersion::count())->toBe(0)
        ->and(SchemaLevel::count())->toBe(0);
});

it('permits only one draft version per schema', function () {
    $version = textbookSchema();

    expectDatabaseRejection(
        fn () => SchemaVersion::factory()->forSchema($version->courseSchema, 2)->create(),
        'one_draft_per_schema',
    );
});

it('clones a published version into an editable draft with the tree intact', function () {
    $v1 = publish(textbookSchema());

    $v2 = app(CloneSchemaVersion::class)->handle($v1);

    expect($v2->version)->toBe(2)
        ->and($v2->isDraft())->toBeTrue()
        ->and($v2->levels)->toHaveCount(3);

    // Parentage survives the clone, remapped onto the new level ids.
    $chapter = $v2->levels()->where('name', 'Chapter')->firstOrFail();
    $part = $v2->levels()->where('name', 'Part')->firstOrFail();
    $topic = $v2->levels()->where('name', 'Topic')->firstOrFail();

    expect($chapter->parent_level_id)->toBe($part->id)
        ->and($topic->parent_level_id)->toBe($chapter->id)
        ->and($part->parent_level_id)->toBeNull();

    // And no level of the clone points back at the original.
    $originalIds = $v1->levels()->pluck('id')->all();
    expect($v2->levels()->pluck('id')->intersect($originalIds))->toBeEmpty();

    // The published original is untouched.
    expect($v1->fresh()->levels()->where('name', 'Chapter')->firstOrFail()->name)->toBe('Chapter');
});

it('refuses to clone while a draft is already open', function () {
    $v1 = publish(textbookSchema());
    app(CloneSchemaVersion::class)->handle($v1);

    expect(fn () => app(CloneSchemaVersion::class)->handle($v1))
        ->toThrow(RuntimeException::class, 'already has an open draft');
});

it('refuses to publish a schema with no levels', function () {
    $version = SchemaVersion::factory()->create();

    expect(fn () => publish($version))
        ->toThrow(RuntimeException::class, 'at least one level');
});

it('refuses to publish a schema where nothing can hold content', function () {
    $version = SchemaVersion::factory()->create();
    SchemaLevel::factory()->create(['schema_version_id' => $version->id]);

    expect(fn () => publish($version))
        ->toThrow(RuntimeException::class, 'allows content');
});

it('refuses a content bearing level that permits no block types', function () {
    $version = SchemaVersion::factory()->create();

    expectDatabaseRejection(
        fn () => SchemaLevel::factory()->create([
            'schema_version_id' => $version->id,
            'allows_content' => true,
            'allowed_block_types' => [],
        ]),
        'schema_levels_content_needs_block_types',
    );
});

it('refuses a max_occurrences below min_occurrences', function () {
    $version = SchemaVersion::factory()->create();

    expectDatabaseRejection(
        fn () => SchemaLevel::factory()->create([
            'schema_version_id' => $version->id,
            'min_occurrences' => 5,
            'max_occurrences' => 2,
        ]),
        'schema_levels_max_occurrences_check',
    );
});
