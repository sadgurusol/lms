<?php

use App\Authorization\Roles;
use App\Models\Course;
use App\Models\CourseGrant;
use App\Models\CoursePublication;
use App\Models\ReviewRequest;
use App\Services\Publishing\PublishCourse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Readiness
|--------------------------------------------------------------------------
*/

it('reports an empty course as not ready', function () {
    [$course] = textbookCourse();
    $author = staff(Roles::CONTENT_AUTHOR);
    grant($author, $course, CourseGrant::OWNER);

    $this->actingAs($author)
        ->get("/studio/courses/{$course->id}/review")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('courses/Review')
            ->where('course.workflow_state', 'draft')
            // A course with no root nodes fails min_occurrences.
            ->where('error_count', fn (int $n) => $n > 0)
            ->where('can.submit', true)
        );
});

it('reports a filled course as ready', function () {
    [$course, $author] = publishableCourse();

    $this->actingAs($author)
        ->get("/studio/courses/{$course->id}/review")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('error_count', 0));
});

/*
|--------------------------------------------------------------------------
| Submit → review → decision
|--------------------------------------------------------------------------
*/

it('submits for review and grants the assigned reviewer', function () {
    [$course, $author] = publishableCourse();
    $reviewer = staff(Roles::CONTENT_REVIEWER);

    $this->actingAs($author)
        ->from("/studio/courses/{$course->id}/review")
        ->post("/studio/courses/{$course->id}/submit", [
            'assigned_to' => $reviewer->id,
            'note' => 'Please check the grammar chapter.',
        ])
        ->assertSessionHas('success');

    expect($course->fresh()->workflow_state)->toBe(Course::STATE_IN_REVIEW)
        ->and(ReviewRequest::where('course_id', $course->id)->where('state', 'open')->exists())->toBeTrue()
        // Assigning granted the reviewer, so the review policy will admit them.
        ->and($reviewer->fresh()->hasGrantOn($course, CourseGrant::REVIEWER))->toBeTrue();
});

/** Separation of duties: an author of the course must never be offered as reviewer. */
it('excludes an editing user from the reviewer list', function () {
    [$course, $author] = publishableCourse();

    // Give the author the reviewer role too — they still must not be offerable.
    $author->assignRole(Roles::CONTENT_REVIEWER);
    $eligible = staff(Roles::CONTENT_REVIEWER);

    $this->actingAs($author)
        ->get("/studio/courses/{$course->id}/review")
        ->assertInertia(function (AssertableInertia $page) use ($author, $eligible) {
            $ids = collect($page->toArray()['props']['reviewers'])->pluck('id');
            expect($ids)->not->toContain($author->id)
                ->and($ids)->toContain($eligible->id);
        });
});

it('refuses to assign an author as their own reviewer', function () {
    [$course, $author] = publishableCourse();
    $author->assignRole(Roles::CONTENT_REVIEWER);

    // Even if the id is forced into the request, the Rule::in guard rejects it.
    $this->actingAs($author)
        ->from("/studio/courses/{$course->id}/review")
        ->post("/studio/courses/{$course->id}/submit", ['assigned_to' => $author->id])
        ->assertSessionHasErrors('assigned_to');

    expect($course->fresh()->workflow_state)->toBe(Course::STATE_DRAFT);
});

it('lets an assigned reviewer approve', function () {
    [$course, $author] = publishableCourse();
    $reviewer = staff(Roles::CONTENT_REVIEWER);

    $this->actingAs($author)->post("/studio/courses/{$course->id}/submit", ['assigned_to' => $reviewer->id]);

    $this->actingAs($reviewer)
        ->from("/studio/courses/{$course->id}/review")
        ->post("/studio/courses/{$course->id}/approve", ['note' => 'Looks good.'])
        ->assertSessionHas('success');

    expect($course->fresh()->workflow_state)->toBe(Course::STATE_APPROVED);
});

it('sends a course back on requested changes', function () {
    [$course, $author] = publishableCourse();
    $reviewer = staff(Roles::CONTENT_REVIEWER);
    $this->actingAs($author)->post("/studio/courses/{$course->id}/submit", ['assigned_to' => $reviewer->id]);

    $this->actingAs($reviewer)
        ->from("/studio/courses/{$course->id}/review")
        ->post("/studio/courses/{$course->id}/request-changes", ['note' => 'Fix the intro.'])
        ->assertSessionHas('success');

    expect($course->fresh()->workflow_state)->toBe(Course::STATE_CHANGES_REQUESTED);
});

it('requires a note when requesting changes', function () {
    [$course, $author] = publishableCourse();
    $reviewer = staff(Roles::CONTENT_REVIEWER);
    $this->actingAs($author)->post("/studio/courses/{$course->id}/submit", ['assigned_to' => $reviewer->id]);

    $this->actingAs($reviewer)
        ->from("/studio/courses/{$course->id}/review")
        ->post("/studio/courses/{$course->id}/request-changes", ['note' => ''])
        ->assertSessionHasErrors('note');
});

/** The one that people forget: an author cannot review their own course. */
it('forbids the author from approving their own course', function () {
    [$course, $author] = publishableCourse();
    $author->assignRole(Roles::CONTENT_REVIEWER);
    $reviewer = staff(Roles::CONTENT_REVIEWER);
    $this->actingAs($author)->post("/studio/courses/{$course->id}/submit", ['assigned_to' => $reviewer->id]);

    $this->actingAs($author)
        ->post("/studio/courses/{$course->id}/approve")
        ->assertForbidden();
});

it('lets an author withdraw an open review', function () {
    [$course, $author] = publishableCourse();
    $reviewer = staff(Roles::CONTENT_REVIEWER);
    $this->actingAs($author)->post("/studio/courses/{$course->id}/submit", ['assigned_to' => $reviewer->id]);

    $this->actingAs($author)
        ->from("/studio/courses/{$course->id}/review")
        ->post("/studio/courses/{$course->id}/withdraw")
        ->assertSessionHas('success');

    expect($course->fresh()->workflow_state)->toBe(Course::STATE_DRAFT);
});

/*
|--------------------------------------------------------------------------
| Publishing
|--------------------------------------------------------------------------
*/

it('publishes an immutable snapshot', function () {
    [$course] = publishableCourse();
    $admin = staff(Roles::ADMIN);

    $this->actingAs($admin)
        ->from("/studio/courses/{$course->id}/review")
        ->post("/studio/courses/{$course->id}/publish", ['changelog' => 'First release.'])
        ->assertSessionHas('success');

    $course->refresh();
    $publication = CoursePublication::sole();

    expect($course->workflow_state)->toBe(Course::STATE_PUBLISHED)
        ->and($course->latest_publication_id)->toBe($publication->id)
        ->and($publication->number)->toBe(1)
        ->and($publication->changelog)->toBe('First release.');
});

/**
 * A published course is read-only. Its content is a frozen snapshot learners
 * read; to change it you start a new version.
 */
it('renders a published course read-only with a revise option', function () {
    [$course, $author] = publishableCourse();
    app(PublishCourse::class)->handle($course, staff(Roles::ADMIN));

    $this->actingAs($author)
        ->get("/studio/courses/{$course->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('course.workflow_state', 'published')
            ->where('course.published_number', 1)
            ->where('can.edit', false)
            ->where('can.revise', true)
        );
});

/**
 * Read-only mode ships the block content with the tree so it can be viewed in
 * place, without opening the editor page. Editable mode does not — content lives
 * on its own editor.
 */
it('ships inline block content in read-only mode only', function () {
    [$course, $author] = publishableCourse();
    app(PublishCourse::class)->handle($course, staff(Roles::ADMIN));

    // Published → read-only → blocks travel with the tree.
    $this->actingAs($author)
        ->get("/studio/courses/{$course->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can.edit', false)
            // Chapter is the first root's first child and carries a block.
            ->where('tree.0.children.0.blocks.0.type', 'rich_text')
        );

    // Reopen as a draft → editable → blocks are not inlined (edited on the page).
    app(PublishCourse::class)->revise($course->fresh(), staff(Roles::ADMIN));

    $this->actingAs($author)
        ->get("/studio/courses/{$course->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can.edit', true)
            ->where('tree.0.children.0.blocks', [])
        );
});

/** The content editor page is read-only for a published course. */
it('makes the content page read-only on a published course', function () {
    [$course, $author] = publishableCourse();
    app(PublishCourse::class)->handle($course, staff(Roles::ADMIN));

    $chapter = $course->nodes()->whereHas('schemaLevel', fn ($q) => $q->where('name', 'Chapter'))->firstOrFail();

    $this->actingAs($author)
        ->get("/studio/course-nodes/{$chapter->id}/content")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('nodes/Content')
            ->where('can.edit', false)
        );
});

it('refuses to edit a published course', function () {
    [$course, $author] = publishableCourse();
    app(PublishCourse::class)->handle($course, staff(Roles::ADMIN));

    $topic = $course->nodes()->whereHas('schemaLevel', fn ($q) => $q->where('name', 'Topic'))->firstOrFail();

    // The owner may edit courses, but not this one while it is published.
    $this->actingAs($author)
        ->patch("/studio/course-nodes/{$topic->id}", ['title' => 'Sneaky edit'])
        ->assertForbidden();

    expect($topic->fresh()->title)->toBe('Topic One');
});

/** Even an admin, whom Gate::before waves through, cannot edit a live snapshot. */
it('refuses to edit a published course even for an admin', function () {
    [$course] = publishableCourse();
    $admin = staff(Roles::ADMIN);
    app(PublishCourse::class)->handle($course, $admin);

    $topic = $course->nodes()->whereHas('schemaLevel', fn ($q) => $q->where('name', 'Topic'))->firstOrFail();

    $this->actingAs($admin)
        ->patch("/studio/course-nodes/{$topic->id}", ['title' => 'Admin edit'])
        ->assertForbidden();
});

it('reopens a published course as a new draft, keeping the publication live', function () {
    [$course, $author] = publishableCourse();
    app(PublishCourse::class)->handle($course, staff(Roles::ADMIN));
    $course->refresh();
    $publicationId = $course->latest_publication_id;

    $this->actingAs($author)
        ->from("/studio/courses/{$course->id}")
        ->post("/studio/courses/{$course->id}/revise")
        ->assertRedirect("/studio/courses/{$course->id}")
        ->assertSessionHas('success');

    $course->refresh();
    expect($course->workflow_state)->toBe(Course::STATE_DRAFT)
        // The publication learners read is untouched.
        ->and($course->latest_publication_id)->toBe($publicationId);

    // And now the course is editable again.
    $topic = $course->nodes()->whereHas('schemaLevel', fn ($q) => $q->where('name', 'Topic'))->firstOrFail();
    $this->actingAs($author)
        ->patch("/studio/course-nodes/{$topic->id}", ['title' => 'Topic One v2'])
        ->assertSessionHas('success');

    expect($topic->fresh()->title)->toBe('Topic One v2');
});

it('refuses to revise a course that is not published', function () {
    [$course, $author] = publishableCourse(); // still a draft

    $this->actingAs($author)
        ->from("/studio/courses/{$course->id}")
        ->post("/studio/courses/{$course->id}/revise")
        ->assertSessionHas('error');

    expect($course->fresh()->workflow_state)->toBe(Course::STATE_DRAFT);
});

it('publishing a revised course produces version 2', function () {
    [$course] = publishableCourse();
    $admin = staff(Roles::ADMIN);

    app(PublishCourse::class)->handle($course, $admin);
    app(PublishCourse::class)->revise($course->fresh(), $admin);

    $this->actingAs($admin)
        ->from("/studio/courses/{$course->id}/review")
        ->post("/studio/courses/{$course->id}/publish", ['changelog' => 'Second release.'])
        ->assertSessionHas('success');

    expect(CoursePublication::where('course_id', $course->id)->count())->toBe(2)
        ->and($course->fresh()->latestPublication->number)->toBe(2);
});

it('blocks publishing a course with errors', function () {
    // Empty course: fails cardinality.
    [$course] = textbookCourse();
    $admin = staff(Roles::ADMIN);

    $this->actingAs($admin)
        ->from("/studio/courses/{$course->id}/review")
        ->post("/studio/courses/{$course->id}/publish")
        ->assertSessionHas('error');

    expect(CoursePublication::count())->toBe(0)
        ->and($course->fresh()->workflow_state)->not->toBe(Course::STATE_PUBLISHED);
});

it('forbids an author without publish permission from publishing', function () {
    [$course, $author] = publishableCourse();

    // A content author holds no course.publish permission.
    $this->actingAs($author)
        ->post("/studio/courses/{$course->id}/publish")
        ->assertForbidden();

    expect(CoursePublication::count())->toBe(0);
});

it('sends a guest away from the review page', function () {
    [$course] = publishableCourse();

    $this->get("/studio/courses/{$course->id}/review")->assertRedirect('/studio/login');
});
