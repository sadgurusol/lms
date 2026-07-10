<?php

use App\Authorization\Roles;
use App\Models\Course;
use App\Models\CourseGrant;
use App\Models\ReviewComment;
use App\Models\ReviewRequest;
use App\Models\User;
use App\Services\Review\ReviewWorkflow;
use App\Services\Tree\CourseTree;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->course, $this->partLevel] = textbookCourse();
    $this->workflow = app(ReviewWorkflow::class);

    $this->author = User::factory()->withRole(Roles::CONTENT_AUTHOR)->create();
    $this->reviewer = User::factory()->withRole(Roles::CONTENT_REVIEWER)->create();

    grant($this->author, $this->course, CourseGrant::AUTHOR);
    grant($this->reviewer, $this->course, CourseGrant::REVIEWER);
});

/*
|--------------------------------------------------------------------------
| Grants: the scope axis
|--------------------------------------------------------------------------
*/

it('scopes editing authority to the courses a user is granted on', function () {
    [$otherCourse] = textbookCourse();

    expect(Gate::forUser($this->author)->allows('update', $this->course))->toBeTrue()
        ->and(Gate::forUser($this->author)->allows('update', $otherCourse))->toBeFalse();
});

it('gives a reviewer read access but no edit authority', function () {
    expect(Gate::forUser($this->reviewer)->allows('view', $this->course))->toBeTrue()
        ->and(Gate::forUser($this->reviewer)->allows('update', $this->course))->toBeFalse();
});

it('busts the grant cache when a grant is revoked', function () {
    expect($this->author->hasGrantOn($this->course, CourseGrant::AUTHOR))->toBeTrue();

    CourseGrant::revoke($this->author, $this->course);

    // A cache the writer must remember to bust is a cache that will not be
    // busted. The model busts it itself, on the model event.
    expect($this->author->hasGrantOn($this->course, CourseGrant::AUTHOR))->toBeFalse();
});

/**
 * The reason CourseGrant::revoke() exists. A mass delete is one SQL statement:
 * no model events fire, so the cache-busting hook never runs and a revoked
 * author keeps editing for the full ten-minute TTL.
 */
it('leaves the grant cache stale if a mass delete bypasses the model events', function () {
    expect($this->author->hasGrantOn($this->course, CourseGrant::AUTHOR))->toBeTrue();

    CourseGrant::where('user_id', $this->author->id)->where('course_id', $this->course->id)->delete();

    expect(CourseGrant::where('user_id', $this->author->id)->exists())->toBeFalse()
        ->and($this->author->hasGrantOn($this->course, CourseGrant::AUTHOR))->toBeTrue();   // stale!
});

/*
|--------------------------------------------------------------------------
| Separation of duties
|--------------------------------------------------------------------------
*/

it('refuses to let a user review a course they author', function () {
    $both = User::factory()->create();
    $both->assignRole([Roles::CONTENT_AUTHOR, Roles::CONTENT_REVIEWER]);

    grant($both, $this->course, CourseGrant::AUTHOR);
    grant($both, $this->course, CourseGrant::REVIEWER);

    $response = Gate::forUser($both)->inspect('review', $this->course);

    expect($response->allowed())->toBeFalse()
        ->and($response->message())->toBe('You cannot review a course you author.');
});

/**
 * If Gate::before let admins through, an admin who wrote the course could
 * approve it themselves and the control would be theatre.
 */
it('does not let even an admin approve a course they author', function () {
    $admin = User::factory()->withRole(Roles::ADMIN)->create();
    grant($admin, $this->course, CourseGrant::OWNER);

    expect(Gate::forUser($admin)->allows('review', $this->course))->toBeFalse();

    // The same admin retains every other power over the course.
    expect(Gate::forUser($admin)->allows('publish', $this->course))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $this->course))->toBeTrue();
});

it('lets an assigned reviewer with no authoring grant review', function () {
    expect(Gate::forUser($this->reviewer)->allows('review', $this->course))->toBeTrue();
});

it('refuses a reviewer who is not assigned to this course', function () {
    $stranger = User::factory()->withRole(Roles::CONTENT_REVIEWER)->create();

    $response = Gate::forUser($stranger)->inspect('review', $this->course);

    expect($response->allowed())->toBeFalse()
        ->and($response->message())->toBe('You are not assigned to review this course.');
});

/*
|--------------------------------------------------------------------------
| The state machine
|--------------------------------------------------------------------------
*/

it('walks a course from draft through review to approved', function () {
    $request = $this->workflow->submit($this->course, $this->author, $this->reviewer, 'Ready for a look');

    expect($this->course->fresh()->workflow_state)->toBe(Course::STATE_IN_REVIEW)
        ->and($request->state)->toBe(ReviewRequest::STATE_OPEN)
        ->and($request->assigned_to)->toBe($this->reviewer->id);

    $request = $this->workflow->approve($request, $this->reviewer, 'Looks good');

    expect($request->state)->toBe(ReviewRequest::STATE_APPROVED)
        ->and($request->decided_by)->toBe($this->reviewer->id)
        ->and($this->course->fresh()->workflow_state)->toBe(Course::STATE_APPROVED);
});

it('sends a course back to changes_requested and lets the author resubmit', function () {
    $first = $this->workflow->submit($this->course, $this->author);
    $this->workflow->requestChanges($first, $this->reviewer, 'Chapter 2 has no topics');

    expect($this->course->fresh()->workflow_state)->toBe(Course::STATE_CHANGES_REQUESTED);

    $second = $this->workflow->submit($this->course->fresh(), $this->author);

    expect($second->id)->not->toBe($first->id)
        ->and($this->course->fresh()->workflow_state)->toBe(Course::STATE_IN_REVIEW)
        // History is kept: the old request is still there, decided.
        ->and(ReviewRequest::where('course_id', $this->course->id)->count())->toBe(2);
});

it('refuses a second open review on the same course', function () {
    $this->workflow->submit($this->course, $this->author);

    expect(fn () => $this->workflow->submit($this->course->fresh(), $this->author))
        ->toThrow(RuntimeException::class, 'cannot be submitted for review');
});

/** The partial unique index is the last line of defence against a race. */
it('has a database guardrail against two open reviews', function () {
    $this->workflow->submit($this->course, $this->author);

    expectDatabaseRejection(
        fn () => ReviewRequest::create([
            'course_id' => $this->course->id,
            'submitted_by' => $this->author->id,
            'state' => ReviewRequest::STATE_OPEN,
        ]),
        'one_open_review_per_course',
    );
});

it('refuses to decide a review twice', function () {
    $request = $this->workflow->submit($this->course, $this->author);
    $this->workflow->approve($request, $this->reviewer);

    expect(fn () => $this->workflow->requestChanges($request->fresh(), $this->reviewer, 'wait'))
        ->toThrow(RuntimeException::class, 'already approved');
});

it('returns a withdrawn review to draft', function () {
    $request = $this->workflow->submit($this->course, $this->author);
    $this->workflow->withdraw($request, $this->author);

    expect($this->course->fresh()->workflow_state)->toBe(Course::STATE_DRAFT)
        ->and($request->fresh()->state)->toBe(ReviewRequest::STATE_WITHDRAWN);
});

/*
|--------------------------------------------------------------------------
| Draft divergence
|--------------------------------------------------------------------------
*/

it('returns an approved course to draft when an author touches the tree', function () {
    $request = $this->workflow->submit($this->course, $this->author);
    $this->workflow->approve($request, $this->reviewer);

    expect($this->course->fresh()->workflow_state)->toBe(Course::STATE_APPROVED);

    app(CourseTree::class)->createNode($this->course->fresh(), $this->partLevel, 'A late addition');

    expect($this->course->fresh()->workflow_state)->toBe(Course::STATE_DRAFT);
});

/**
 * Editing *during* review must not reset the state — authors fix the typos the
 * reviewer just flagged, and a tree frozen mid-review causes more pain than it
 * prevents.
 */
it('leaves a course in review when the author edits during review', function () {
    $this->workflow->submit($this->course, $this->author);

    app(CourseTree::class)->createNode($this->course->fresh(), $this->partLevel, 'Fixing a typo');

    expect($this->course->fresh()->workflow_state)->toBe(Course::STATE_IN_REVIEW);
});

/*
|--------------------------------------------------------------------------
| Anchored comments
|--------------------------------------------------------------------------
*/

it('anchors a comment to a node and resolves it', function () {
    $request = $this->workflow->submit($this->course, $this->author);
    $node = app(CourseTree::class)->createNode($this->course->fresh(), $this->partLevel, 'Part One');

    $comment = $this->workflow->comment(
        $request, $this->reviewer, 'This part needs a summary',
        ReviewComment::ANCHOR_NODE, $node->id,
    );

    expect($comment->isResolved())->toBeFalse();

    $reply = $this->workflow->comment(
        $request, $this->author, 'Added one',
        ReviewComment::ANCHOR_NODE, $node->id, $comment->id,
    );

    expect($comment->replies()->count())->toBe(1)
        ->and($reply->parent_comment_id)->toBe($comment->id);

    $resolved = $this->workflow->resolve($comment, $this->author);

    expect($resolved->isResolved())->toBeTrue()
        ->and($resolved->resolved_by)->toBe($this->author->id);
});

it('requires an anchor id for node and block comments, and forbids one for course comments', function () {
    $request = $this->workflow->submit($this->course, $this->author);

    expectDatabaseRejection(
        fn () => ReviewComment::create([
            'review_request_id' => $request->id,
            'author_id' => $this->reviewer->id,
            'body' => 'anchored to nothing',
            'anchor_type' => ReviewComment::ANCHOR_NODE,
            'anchor_id' => null,
        ]),
        'review_comments_anchor_id_check',
    );

    expectDatabaseRejection(
        fn () => ReviewComment::create([
            'review_request_id' => $request->id,
            'author_id' => $this->reviewer->id,
            'body' => 'course comment with an anchor',
            'anchor_type' => ReviewComment::ANCHOR_COURSE,
            'anchor_id' => $this->course->id,
        ]),
        'review_comments_anchor_id_check',
    );
});
