<?php

use App\Authorization\Roles;
use App\Models\Course;
use App\Models\CourseGrant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** @return array<string, mixed> */
function coursePayload(string $schemaVersionId, array $overrides = []): array
{
    return [
        'title' => 'Grade 10 English',
        'code' => 'ENG-10',
        'subject' => 'English',
        'grade_band' => 'Grade 10',
        'language' => 'en',
        'schema_version_id' => $schemaVersionId,
        ...$overrides,
    ];
}

/*
|--------------------------------------------------------------------------
| Creating
|--------------------------------------------------------------------------
*/

it('creates a course and makes the creator its owner', function () {
    $version = publish(textbookSchema());
    $author = staff(Roles::CONTENT_AUTHOR);

    $this->actingAs($author)
        ->from('/studio/courses')
        ->post('/studio/courses', coursePayload($version->id))
        ->assertSessionHas('success');

    $course = Course::sole();

    expect($course->title)->toBe('Grade 10 English')
        ->and($course->workflow_state)->toBe(Course::STATE_DRAFT)
        ->and($course->schema_version_id)->toBe($version->id)
        ->and($course->created_by)->toBe($author->id);

    // Without the grant, CoursePolicy::view() would hide the course from the
    // very author who just created it.
    expect(CourseGrant::where('course_id', $course->id)->where('user_id', $author->id)->value('role'))
        ->toBe(CourseGrant::OWNER);
});

/** The creator must be able to open what they just made. */
it('shows an author the course they created', function () {
    $version = publish(textbookSchema());
    $author = staff(Roles::CONTENT_AUTHOR);

    $this->actingAs($author)->post('/studio/courses', coursePayload($version->id));

    $this->get('/studio/courses')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('courses/Index')
            ->has('courses', 1)
            ->where('courses.0.title', 'Grade 10 English')
            ->where('courses.0.workflow_state', Course::STATE_DRAFT)
            ->where('courses.0.node_count', 0)
            ->where('courses.0.published_number', null)
            ->where('courses.0.has_pending_draft', false)
            ->where('courses.0.schema.version', 1)
        );
});

/**
 * A draft schema version's levels are still moving, and `trg_courses_schema_pinned`
 * forbids re-pointing a course afterwards. Binding to one strands the course.
 */
it('refuses a course built on a draft schema version', function () {
    $draft = textbookSchema();

    $this->actingAs(staff(Roles::ADMIN))
        ->from('/studio/courses')
        ->post('/studio/courses', coursePayload($draft->id))
        ->assertSessionHasErrors('schema_version_id');

    expect(Course::count())->toBe(0);
});

it('refuses a duplicate course code', function () {
    $version = publish(textbookSchema());
    $this->actingAs(staff(Roles::ADMIN));

    $this->post('/studio/courses', coursePayload($version->id))->assertSessionHas('success');

    $this->from('/studio/courses')
        ->post('/studio/courses', coursePayload($version->id, ['title' => 'Another']))
        ->assertSessionHasErrors('code');

    expect(Course::count())->toBe(1);
});

it('accepts a course with no code', function () {
    $version = publish(textbookSchema());

    $this->actingAs(staff(Roles::ADMIN))
        ->post('/studio/courses', coursePayload($version->id, ['code' => null]))
        ->assertSessionHas('success');

    expect(Course::sole()->code)->toBeNull();
});

it('refuses a course created by a reviewer', function () {
    $version = publish(textbookSchema());

    $this->actingAs(staff(Roles::CONTENT_REVIEWER))
        ->post('/studio/courses', coursePayload($version->id))
        ->assertForbidden();

    expect(Course::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| What the list shows
|--------------------------------------------------------------------------
*/

/** `course.view.any` is the only bypass. An author sees their grants and nothing else. */
it('hides another author s course', function () {
    $version = publish(textbookSchema());
    $mine = staff(Roles::CONTENT_AUTHOR);
    $theirs = staff(Roles::CONTENT_AUTHOR);

    $this->actingAs($theirs)->post('/studio/courses', coursePayload($version->id));
    $this->post('/studio/logout');

    $this->actingAs($mine)
        ->get('/studio/courses')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('courses', 0));
});

it('shows every course to an admin', function () {
    $version = publish(textbookSchema());
    $author = staff(Roles::CONTENT_AUTHOR);

    $this->actingAs($author)->post('/studio/courses', coursePayload($version->id));
    $this->post('/studio/logout');

    // The admin holds no grant on that course, only `course.view.any`.
    $admin = staff(Roles::ADMIN);
    expect($admin->grantsByCourse())->toBe([]);

    $this->actingAs($admin)
        ->get('/studio/courses')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('courses', 1)
            ->where('can.create', true)
        );
});

it('offers a reviewer no way to create a course', function () {
    $this->actingAs(staff(Roles::CONTENT_REVIEWER))
        ->get('/studio/courses')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.create', false));
});

/*
|--------------------------------------------------------------------------
| Schema versions offered
|--------------------------------------------------------------------------
*/

it('offers only published schema versions', function () {
    $published = publish(textbookSchema());
    textbookSchema(); // a second schema, left in draft

    $this->actingAs(staff(Roles::ADMIN))
        ->get('/studio/courses')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('schema_versions', 1)
            ->where('schema_versions.0.id', $published->id)
            ->where('schema_versions.0.label', "{$published->courseSchema->name} · v1")
        );
});

/**
 * A published version whose schema was archived (soft-deleted) has no live
 * parent to name. It must be filtered out, not crash the page reading a null
 * courseSchema->name.
 */
it('skips a published version whose schema was archived', function () {
    $live = publish(textbookSchema());
    $orphaned = publish(textbookSchema());
    $orphaned->courseSchema->delete(); // soft-delete the parent schema

    $this->actingAs(staff(Roles::ADMIN))
        ->get('/studio/courses')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('schema_versions', 1)
            ->where('schema_versions.0.id', $live->id)
        );
});

/** With nothing published there is nothing to build on, and the UI says so. */
it('offers no schema versions when none are published', function () {
    textbookSchema();

    $this->actingAs(staff(Roles::ADMIN))
        ->get('/studio/courses')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('schema_versions', 0));
});

it('sends a guest away from the course list', function () {
    $this->get('/studio/courses')->assertRedirect('/studio/login');
});

it('refuses a learner', function () {
    $learner = User::factory()->withRole(Roles::LEARNER)->create();

    $this->actingAs($learner)->get('/studio/courses')->assertForbidden();
});
