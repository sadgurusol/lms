<?php

use App\Authorization\Roles;
use App\Models\Course;
use App\Models\CourseSchema;
use App\Models\SchemaVersion;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('deletes a schema that no course uses', function () {
    $schema = CourseSchema::factory()->create(['name' => 'Orphan Schema']);
    SchemaVersion::factory()->forSchema($schema)->create();

    $this->actingAs(staff(Roles::ADMIN))
        ->from('/studio/schemas')
        ->delete("/studio/schemas/{$schema->id}")
        ->assertRedirect('/studio/schemas')
        ->assertSessionHas('success');

    expect(CourseSchema::find($schema->id))->toBeNull()               // hidden by soft delete
        ->and(CourseSchema::withTrashed()->find($schema->id))->not->toBeNull();
});

it('refuses to delete a schema a course is bound to', function () {
    // textbookCourse() binds a course to a published schema version.
    [$course] = textbookCourse();
    $schema = $course->schemaVersion->courseSchema;

    $this->actingAs(staff(Roles::ADMIN))
        ->from('/studio/schemas')
        ->delete("/studio/schemas/{$schema->id}")
        ->assertRedirect('/studio/schemas')
        ->assertSessionHas('error');

    expect(CourseSchema::find($schema->id))->not->toBeNull();
});

it('reports how many courses use each schema on the index', function () {
    [$course] = textbookCourse();
    $usedSchema = $course->schemaVersion->courseSchema;

    $orphan = CourseSchema::factory()->create();
    SchemaVersion::factory()->forSchema($orphan)->create();

    $this->actingAs(staff(Roles::ADMIN))
        ->get('/studio/schemas')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('schemas/Index')
            ->where('can.delete', true)
            ->where('schemas', fn ($schemas) => collect($schemas)
                ->firstWhere('id', $usedSchema->id)['course_count'] === 1
                && collect($schemas)->firstWhere('id', $orphan->id)['course_count'] === 0)
        );
});

it('forbids a non-admin from deleting a schema', function () {
    $schema = CourseSchema::factory()->create();
    SchemaVersion::factory()->forSchema($schema)->create();

    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->delete("/studio/schemas/{$schema->id}")
        ->assertForbidden();

    expect(CourseSchema::find($schema->id))->not->toBeNull();
});

it('does not offer delete to a role that cannot archive schemas', function () {
    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->get('/studio/schemas')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.delete', false));
});
