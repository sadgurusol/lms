<?php

use App\Authorization\Roles;
use App\Models\Course;
use App\Models\CourseGrant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * A course owned by a fresh author (owner grant), plus that owner.
 *
 * @return array{Course, User}
 */
function ownedCourseWithTeam(): array
{
    [$course] = textbookCourse();
    $owner = staff(Roles::CONTENT_AUTHOR);
    grant($owner, $course, CourseGrant::OWNER);

    return [$course, $owner];
}

/*
|--------------------------------------------------------------------------
| Viewing
|--------------------------------------------------------------------------
*/

it('lists the team to an owner', function () {
    [$course, $owner] = ownedCourseWithTeam();

    $this->actingAs($owner)
        ->get("/studio/courses/{$course->id}/team")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('courses/Team')
            ->has('grants', 1)
            ->where('grants.0.role', 'owner')
            ->where('grants.0.user.id', $owner->id)
            ->where('can.manage', true)
            ->has('assignable')
        );
});

it('shows the team read-only to a non-owner author', function () {
    [$course] = ownedCourseWithTeam();
    $author = staff(Roles::CONTENT_AUTHOR);
    grant($author, $course, CourseGrant::AUTHOR);

    $this->actingAs($author)
        ->get("/studio/courses/{$course->id}/team")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.manage', false));
});

it('refuses the team page to someone with no grant', function () {
    [$course] = ownedCourseWithTeam();

    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->get("/studio/courses/{$course->id}/team")
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Assigning
|--------------------------------------------------------------------------
*/

it('adds an author', function () {
    [$course, $owner] = ownedCourseWithTeam();
    $author = staff(Roles::CONTENT_AUTHOR);

    $this->actingAs($owner)
        ->from("/studio/courses/{$course->id}/team")
        ->post("/studio/courses/{$course->id}/grants", ['user_id' => $author->id, 'role' => 'author'])
        ->assertSessionHas('success');

    expect($author->fresh()->hasGrantOn($course, CourseGrant::AUTHOR))->toBeTrue();
});

/** Separation of duties: an author of the course cannot also review it. */
it('refuses to make an author into a reviewer', function () {
    [$course, $owner] = ownedCourseWithTeam();
    $author = staff(Roles::CONTENT_REVIEWER);
    grant($author, $course, CourseGrant::AUTHOR);

    $this->actingAs($owner)
        ->from("/studio/courses/{$course->id}/team")
        ->post("/studio/courses/{$course->id}/grants", ['user_id' => $author->id, 'role' => 'reviewer'])
        ->assertSessionHasErrors('role');

    expect($author->fresh()->hasGrantOn($course, CourseGrant::REVIEWER))->toBeFalse();
});

it('refuses to make a reviewer into an author', function () {
    [$course, $owner] = ownedCourseWithTeam();
    $reviewer = staff(Roles::CONTENT_REVIEWER);
    grant($reviewer, $course, CourseGrant::REVIEWER);

    $this->actingAs($owner)
        ->from("/studio/courses/{$course->id}/team")
        ->post("/studio/courses/{$course->id}/grants", ['user_id' => $reviewer->id, 'role' => 'author'])
        ->assertSessionHasErrors('role');
});

it('refuses a duplicate role', function () {
    [$course, $owner] = ownedCourseWithTeam();

    $this->actingAs($owner)
        ->from("/studio/courses/{$course->id}/team")
        ->post("/studio/courses/{$course->id}/grants", ['user_id' => $owner->id, 'role' => 'owner'])
        ->assertSessionHasErrors('role');
});

it('refuses a client-provisioned user as a team member', function () {
    [$course, $owner] = ownedCourseWithTeam();
    $launched = User::factory()->clientProvisioned()->create();

    $this->actingAs($owner)
        ->from("/studio/courses/{$course->id}/team")
        ->post("/studio/courses/{$course->id}/grants", ['user_id' => $launched->id, 'role' => 'author'])
        ->assertSessionHasErrors('user_id');
});

it('refuses grant management by a non-owner author', function () {
    [$course] = ownedCourseWithTeam();
    $author = staff(Roles::CONTENT_AUTHOR);
    grant($author, $course, CourseGrant::AUTHOR);
    $someone = staff(Roles::CONTENT_AUTHOR);

    $this->actingAs($author)
        ->post("/studio/courses/{$course->id}/grants", ['user_id' => $someone->id, 'role' => 'author'])
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Removing — and the cache it must bust
|--------------------------------------------------------------------------
*/

it('removes a grant and busts the users grant cache', function () {
    [$course, $owner] = ownedCourseWithTeam();
    $author = staff(Roles::CONTENT_AUTHOR);
    $grant = CourseGrant::create(['user_id' => $author->id, 'course_id' => $course->id, 'role' => 'author']);

    // Warm the author's grant cache, then revoke.
    expect($author->hasGrantOn($course, CourseGrant::AUTHOR))->toBeTrue();
    expect(Cache::has(User::grantCacheKey($author->id)))->toBeTrue();

    $this->actingAs($owner)
        ->from("/studio/courses/{$course->id}/team")
        ->delete("/studio/course-grants/{$grant->id}")
        ->assertSessionHas('success');

    // The cache was busted, so the revoked author no longer resolves the grant.
    expect(CourseGrant::count())->toBe(1) // only the owner remains
        ->and($author->fresh()->hasGrantOn($course, CourseGrant::AUTHOR))->toBeFalse();
});

it('will not remove the last owner', function () {
    [$course, $owner] = ownedCourseWithTeam();
    $grant = CourseGrant::where('course_id', $course->id)->where('role', 'owner')->sole();

    $this->actingAs($owner)
        ->from("/studio/courses/{$course->id}/team")
        ->delete("/studio/course-grants/{$grant->id}")
        ->assertSessionHas('error', 'A course must keep at least one owner.');

    expect(CourseGrant::where('course_id', $course->id)->where('role', 'owner')->count())->toBe(1);
});
