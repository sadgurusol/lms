<?php

use App\Authorization\Roles;
use App\Models\Course;
use App\Models\CourseSchema;
use App\Models\SchemaLevel;
use App\Models\SchemaVersion;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Only an admin may shape a schema. An author holds `schema.view` and nothing
 * more, because a schema version is the contract every course bound to it was
 * authored against — see Permissions::forRoles().
 */
function admin(): User
{
    return staff(Roles::ADMIN);
}

/** @return array<string, mixed> */
function levelPayload(array $overrides = []): array
{
    return [
        'parent_level_id' => null,
        'name' => 'Part',
        'plural_name' => 'Parts',
        'min_occurrences' => 1,
        'max_occurrences' => null,
        'allows_content' => false,
        'allowed_block_types' => [],
        'allows_assessment' => false,
        'numbering_style' => 'roman',
        'label_template' => 'Part {n}',
        ...$overrides,
    ];
}

/** A draft version owned by a fresh schema, with no levels yet. */
function draftVersion(): SchemaVersion
{
    return SchemaVersion::factory()->forSchema(CourseSchema::factory()->create())->create();
}

/*
|--------------------------------------------------------------------------
| Listing and creating schemas
|--------------------------------------------------------------------------
*/

it('lists schemas with their draft and published versions', function () {
    $version = publish(textbookSchema());
    $schema = $version->courseSchema;

    $this->actingAs(admin())
        ->get('/studio/schemas')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('schemas/Index')
            ->has('schemas', 1)
            ->where('schemas.0.name', $schema->name)
            ->where('schemas.0.version_count', 1)
            ->where('schemas.0.published.version', 1)
            ->where('schemas.0.published.status', SchemaVersion::STATUS_PUBLISHED)
            ->where('schemas.0.draft', null)
            ->where('can.create', true)
        );
});

/** An author reads the list. The button is not rendered because the server said so. */
it('does not offer schema creation to an author', function () {
    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->get('/studio/schemas')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.create', false));
});

it('refuses a schema created by an author', function () {
    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->post('/studio/schemas', ['name' => 'Sneaky'])
        ->assertForbidden();

    expect(CourseSchema::count())->toBe(0);
});

it('creates a schema with an open draft and lands on it', function () {
    $response = $this->actingAs(admin())
        ->post('/studio/schemas', ['name' => 'Textbook (3-tier)', 'description' => 'Part → Chapter → Topic']);

    $schema = CourseSchema::sole();
    $draft = SchemaVersion::sole();

    expect($schema->slug)->toBe('textbook-3-tier')
        ->and($draft->version)->toBe(1)
        ->and($draft->status)->toBe(SchemaVersion::STATUS_DRAFT);

    $response->assertRedirect("/studio/schema-versions/{$draft->id}")
        ->assertSessionHas('success');
});

it('keeps slugs unique across schemas that share a name', function () {
    $this->actingAs(admin());

    $this->post('/studio/schemas', ['name' => 'Textbook']);
    $this->post('/studio/schemas', ['name' => 'Textbook']);

    expect(CourseSchema::orderBy('slug')->pluck('slug')->all())->toBe(['textbook', 'textbook-2']);
});

/*
|--------------------------------------------------------------------------
| The version screen
|--------------------------------------------------------------------------
*/

it('shows a draft version as editable', function () {
    $version = textbookSchema();

    $this->actingAs(admin())
        ->get("/studio/schema-versions/{$version->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('schemas/Show')
            ->where('version.editable', true)
            ->where('can.update', true)
            ->where('can.publish', true)
            // Nothing to clone: a draft is already the editable copy.
            ->where('can.clone', false)
            ->has('levels', 3)
            ->where('levels.0.name', 'Part')
            ->where('levels.0.depth', 0)
            ->where('levels.2.name', 'Topic')
            ->where('levels.2.depth', 2)
            ->where('courses_bound', 0)
            ->has('options.block_types')
            ->has('options.numbering_styles')
        );
});

/** The UI must not offer edits it knows the database will refuse. */
it('shows a published version as immutable and cloneable', function () {
    $version = publish(textbookSchema());
    Course::factory()->onSchema($version)->create();

    $this->actingAs(admin())
        ->get("/studio/schema-versions/{$version->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('version.editable', false)
            ->where('can.update', false)
            ->where('can.publish', false)
            ->where('can.clone', true)
            // Editing this version would rewrite the meaning of that course.
            ->where('courses_bound', 1)
        );
});

it('tells an author they may look but not touch', function () {
    $version = textbookSchema();

    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->get("/studio/schema-versions/{$version->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            // The version *is* a draft; the author still may not edit it.
            ->where('version.editable', true)
            ->where('can.update', false)
            ->where('can.publish', false)
        );
});

/*
|--------------------------------------------------------------------------
| Levels
|--------------------------------------------------------------------------
*/

it('derives a level s depth from its parent', function () {
    $version = draftVersion();
    $this->actingAs(admin())->from("/studio/schema-versions/{$version->id}");

    $this->post("/studio/schema-versions/{$version->id}/levels", levelPayload())
        ->assertSessionHas('success');

    $part = SchemaLevel::sole();

    $this->post("/studio/schema-versions/{$version->id}/levels", levelPayload([
        'parent_level_id' => $part->id,
        'name' => 'Chapter',
        'plural_name' => 'Chapters',
        // A client that supplies its own depth is ignored, not obeyed.
        'depth' => 99,
    ]))->assertSessionHas('success');

    $chapter = SchemaLevel::where('name', 'Chapter')->sole();

    expect($part->depth)->toBe(0)
        ->and($chapter->depth)->toBe(1)
        ->and($chapter->parent_level_id)->toBe($part->id);
});

it('appends siblings in insertion order', function () {
    $version = draftVersion();
    $this->actingAs(admin())->from("/studio/schema-versions/{$version->id}");

    foreach (['Unit', 'Appendix'] as $name) {
        $this->post("/studio/schema-versions/{$version->id}/levels", levelPayload([
            'name' => $name, 'plural_name' => "{$name}s",
        ]));
    }

    expect(SchemaLevel::orderBy('sort_key')->pluck('name')->all())->toBe(['Unit', 'Appendix']);
});

/** The CHECK constraint is the authority; the controller only translates it. */
it('refuses a content level that permits no block types', function () {
    $version = draftVersion();

    $this->actingAs(admin())
        ->from("/studio/schema-versions/{$version->id}")
        ->post("/studio/schema-versions/{$version->id}/levels", levelPayload([
            'allows_content' => true,
            'allowed_block_types' => [],
        ]))
        ->assertSessionHas('error', 'A level that allows content must permit at least one block type.');

    expect(SchemaLevel::count())->toBe(0);
});

it('rejects an unknown block type', function () {
    $version = draftVersion();

    $this->actingAs(admin())
        ->from("/studio/schema-versions/{$version->id}")
        ->post("/studio/schema-versions/{$version->id}/levels", levelPayload([
            'allows_content' => true,
            'allowed_block_types' => ['rich_text', 'malware'],
        ]))
        ->assertSessionHasErrors('allowed_block_types.1');
});

/** A level cannot be adopted into another version's tree. */
it('rejects a parent from a different schema version', function () {
    $mine = draftVersion();
    $theirs = textbookSchema();
    $foreign = $theirs->levels()->where('name', 'Part')->sole();

    $this->actingAs(admin())
        ->from("/studio/schema-versions/{$mine->id}")
        ->post("/studio/schema-versions/{$mine->id}/levels", levelPayload(['parent_level_id' => $foreign->id]))
        ->assertSessionHasErrors('parent_level_id');
});

it('renames a level without re-parenting it', function () {
    $version = textbookSchema();
    $chapter = $version->levels()->where('name', 'Chapter')->sole();
    $topic = $version->levels()->where('name', 'Topic')->sole();

    $this->actingAs(admin())
        ->from("/studio/schema-versions/{$version->id}")
        ->patch("/studio/schema-levels/{$chapter->id}", levelPayload([
            // Moving a level would silently reinterpret depth. The field is dropped.
            'parent_level_id' => $topic->id,
            'name' => 'Section',
            'plural_name' => 'Sections',
            'allows_content' => true,
            'allowed_block_types' => ['rich_text'],
            'numbering_style' => 'numeric',
            'label_template' => 'Section {n}',
        ]))
        ->assertSessionHas('success');

    $chapter->refresh();

    expect($chapter->name)->toBe('Section')
        ->and($chapter->depth)->toBe(1)
        ->and($chapter->parent_level_id)->toBe($version->levels()->where('name', 'Part')->sole()->id);
});

it('cascades a deleted level onto its children', function () {
    $version = textbookSchema();
    $part = $version->levels()->where('name', 'Part')->sole();

    $this->actingAs(admin())
        ->from("/studio/schema-versions/{$version->id}")
        ->delete("/studio/schema-levels/{$part->id}")
        ->assertSessionHas('success');

    expect(SchemaLevel::count())->toBe(0);
});

it('refuses level edits from an author', function () {
    $version = draftVersion();

    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->post("/studio/schema-versions/{$version->id}/levels", levelPayload())
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Publishing
|--------------------------------------------------------------------------
*/

it('refuses to publish a version with no levels', function () {
    $version = draftVersion();

    $this->actingAs(admin())
        ->from("/studio/schema-versions/{$version->id}")
        ->post("/studio/schema-versions/{$version->id}/publish")
        ->assertSessionHas('error', 'A schema version must define at least one level before publishing.');

    expect($version->fresh()->status)->toBe(SchemaVersion::STATUS_DRAFT);
});

it('refuses to publish a version where nothing can hold content', function () {
    $version = draftVersion();
    SchemaLevel::factory()->create(['schema_version_id' => $version->id]);

    $this->actingAs(admin())
        ->from("/studio/schema-versions/{$version->id}")
        ->post("/studio/schema-versions/{$version->id}/publish")
        ->assertSessionHas('error', 'A schema version must have at least one level that allows content.');
});

it('publishes a coherent draft', function () {
    $version = textbookSchema();

    $this->actingAs(admin())
        ->from("/studio/schema-versions/{$version->id}")
        ->post("/studio/schema-versions/{$version->id}/publish")
        ->assertSessionHas('success', 'Version 1 published. It is now immutable.');

    $version->refresh();

    expect($version->status)->toBe(SchemaVersion::STATUS_PUBLISHED)
        ->and($version->published_at)->not->toBeNull()
        ->and($version->published_by)->not->toBeNull();
});

it('refuses to publish twice', function () {
    $version = publish(textbookSchema());

    $this->actingAs(admin())
        ->from("/studio/schema-versions/{$version->id}")
        ->post("/studio/schema-versions/{$version->id}/publish")
        ->assertSessionHas('error');
});

it('refuses a publish from an author', function () {
    $version = textbookSchema();

    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->post("/studio/schema-versions/{$version->id}/publish")
        ->assertForbidden();
});

/**
 * The trigger holds even if the UI, the policy and the controller all fail. The
 * controller's only job is to say why in English.
 */
it('translates the immutability trigger into a human sentence', function () {
    $version = publish(textbookSchema());
    $topic = $version->levels()->where('name', 'Topic')->sole();
    $immutable = 'This version is published and immutable. Clone it into a draft first.';

    $this->actingAs(admin())->from("/studio/schema-versions/{$version->id}");

    $this->post("/studio/schema-versions/{$version->id}/levels", levelPayload(['name' => 'Sneaky']))
        ->assertSessionHas('error', $immutable);

    $this->patch("/studio/schema-levels/{$topic->id}", levelPayload([
        'name' => 'Renamed', 'plural_name' => 'Renamed',
        'allows_content' => true, 'allowed_block_types' => ['rich_text'],
    ]))->assertSessionHas('error', $immutable);

    $this->delete("/studio/schema-levels/{$topic->id}")
        ->assertSessionHas('error', $immutable);

    expect($version->levels()->count())->toBe(3)
        ->and($topic->fresh()->name)->toBe('Topic');
});

/*
|--------------------------------------------------------------------------
| Cloning
|--------------------------------------------------------------------------
*/

it('clones a published version into a draft, rebuilding the tree', function () {
    $version = publish(textbookSchema());

    $response = $this->actingAs(admin())
        ->from("/studio/schema-versions/{$version->id}")
        ->post("/studio/schema-versions/{$version->id}/clone");

    $draft = SchemaVersion::where('status', SchemaVersion::STATUS_DRAFT)->sole();

    $response->assertRedirect("/studio/schema-versions/{$draft->id}")
        ->assertSessionHas('success', 'Cloned into draft version 2.');

    expect($draft->version)->toBe(2)
        ->and($draft->notes)->toBe('Cloned from version 1');

    $levels = $draft->levels()->orderBy('depth')->get()->keyBy('name');

    expect($levels)->toHaveCount(3)
        // Parents were remapped onto the copies, never left pointing at the original.
        ->and($levels['Chapter']->parent_level_id)->toBe($levels['Part']->id)
        ->and($levels['Topic']->parent_level_id)->toBe($levels['Chapter']->id)
        ->and($levels['Topic']->allowed_block_types)->toBe(['rich_text', 'video', 'image', 'attachment', 'embed']);

    // The published rows are untouched, so courses bound to v1 still render.
    expect($version->fresh()->levels()->count())->toBe(3);
});

it('refuses a second clone while a draft is open', function () {
    $version = publish(textbookSchema());
    $this->actingAs(admin())->from("/studio/schema-versions/{$version->id}");

    $this->post("/studio/schema-versions/{$version->id}/clone")->assertSessionHas('success');

    $this->from("/studio/schema-versions/{$version->id}")
        ->post("/studio/schema-versions/{$version->id}/clone")
        ->assertSessionHas('error', 'This schema already has an open draft version. Publish or discard it first.');

    expect(SchemaVersion::count())->toBe(2);
});

it('refuses a clone from an author', function () {
    $version = publish(textbookSchema());

    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->post("/studio/schema-versions/{$version->id}/clone")
        ->assertForbidden();
});
