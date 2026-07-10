<?php

namespace App\Policies;

use App\Authorization\Permissions;
use App\Models\Course;
use App\Models\CourseGrant;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Authority over a course.
 *
 * Admins bypass all of this via Gate::before. Everyone else needs both a global
 * permission (what kind of thing may you do) and a course grant (on which
 * courses may you do it).
 *
 * Node and block policies delegate here. Never authorize a CourseNode on its
 * own — authority always flows from the course.
 */
class CoursePolicy
{
    public function view(User $user, Course $course): bool
    {
        return $user->can(Permissions::COURSE_VIEW_ANY)
            || ($user->can(Permissions::COURSE_VIEW_GRANTED) && $user->hasGrantOn($course, [
                CourseGrant::OWNER, CourseGrant::AUTHOR, CourseGrant::REVIEWER,
            ]));
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::COURSE_CREATE);
    }

    public function update(User $user, Course $course): bool
    {
        return $user->can(Permissions::COURSE_UPDATE)
            && $user->hasGrantOn($course, CourseGrant::EDITING);
    }

    public function submitForReview(User $user, Course $course): bool
    {
        return $user->can(Permissions::COURSE_SUBMIT_REVIEW)
            && $user->hasGrantOn($course, CourseGrant::EDITING);
    }

    /**
     * A user may not review a course they author.
     *
     * This is the single most valuable line of authorization code in the
     * system, and it is the one people forget. A user may legitimately hold
     * both `content_author` and `content_reviewer` globally; separation of
     * duties is a property of the *course*, not of the role list.
     */
    public function review(User $user, Course $course): Response
    {
        if ($user->hasGrantOn($course, CourseGrant::EDITING)) {
            return Response::deny('You cannot review a course you author.');
        }

        return $user->can(Permissions::COURSE_REVIEW)
            && $user->hasGrantOn($course, CourseGrant::REVIEWER)
                ? Response::allow()
                : Response::deny('You are not assigned to review this course.');
    }

    /**
     * Publishing is admin-only by default: it is the only action a learner can
     * observe. Grant it to owners deliberately, per course, or not at all.
     */
    public function publish(User $user, Course $course): bool
    {
        return $user->can(Permissions::COURSE_PUBLISH);
    }

    public function manageGrants(User $user, Course $course): bool
    {
        return $user->can(Permissions::COURSE_GRANT)
            && $user->hasGrantOn($course, CourseGrant::OWNER);
    }

    public function delete(User $user, Course $course): bool
    {
        return $user->can(Permissions::COURSE_DELETE);
    }
}
