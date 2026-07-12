<?php

namespace App\Http\Controllers\Studio;

use App\Authorization\Roles;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseGrant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The course team: who owns, authors, and reviews it.
 *
 * Only an owner may manage grants (CoursePolicy::manageGrants). Separation of
 * duties is enforced here, not just at review time: a person cannot hold an
 * editing grant and a reviewer grant on the same course, so the reviewer of a
 * chapter is never also its author.
 */
class CourseGrantController extends Controller
{
    private const ROLES = [CourseGrant::OWNER, CourseGrant::AUTHOR, CourseGrant::REVIEWER];

    public function index(Request $request, Course $course): Response
    {
        Gate::authorize('view', $course);
        $mayManage = Gate::allows('manageGrants', $course);

        $grants = CourseGrant::query()
            ->where('course_id', $course->id)
            ->with('user:id,name,email')
            ->get()
            ->map(fn (CourseGrant $g) => [
                'id' => $g->id,
                'role' => $g->role,
                'user' => ['id' => $g->user->id, 'name' => $g->user->name, 'email' => $g->user->email],
            ]);

        return Inertia::render('courses/Team', [
            'course' => ['id' => $course->id, 'title' => $course->title],
            'grants' => $grants,
            // Staff who can be assigned: content roles, minus client-provisioned.
            'assignable' => User::query()
                ->role([Roles::ADMIN, Roles::CONTENT_AUTHOR, Roles::CONTENT_REVIEWER])
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email]),
            'roles' => self::ROLES,
            'can' => ['manage' => $mayManage],
        ]);
    }

    public function store(Request $request, Course $course): RedirectResponse
    {
        Gate::authorize('manageGrants', $course);

        $data = $request->validate([
            'user_id' => ['required', 'uuid', Rule::exists('users', 'id')],
            'role' => ['required', Rule::in(self::ROLES)],
        ]);

        $user = User::findOrFail($data['user_id']);

        if ($user->isClientProvisioned() || ! $user->isActive()) {
            return back()->withErrors(['user_id' => 'That user cannot be a course team member.']);
        }

        if ($user->hasGrantOn($course, $data['role'])) {
            return back()->withErrors(['role' => 'They already hold that role.']);
        }

        // Separation of duties: editing and reviewing the same course are mutually
        // exclusive, so a reviewer never signs off on their own work.
        $isEditing = in_array($data['role'], CourseGrant::EDITING, true);
        if ($isEditing && $user->hasGrantOn($course, CourseGrant::REVIEWER)) {
            return back()->withErrors(['role' => 'They review this course, so they cannot also edit it.']);
        }
        if ($data['role'] === CourseGrant::REVIEWER && $user->hasGrantOn($course, CourseGrant::EDITING)) {
            return back()->withErrors(['role' => 'They author this course, so they cannot also review it.']);
        }

        CourseGrant::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'role' => $data['role'],
            'granted_by' => $request->user()->id,
        ]);

        return back()->with('success', "Added {$user->name} as {$data['role']}.");
    }

    public function destroy(Request $request, CourseGrant $courseGrant): RedirectResponse
    {
        Gate::authorize('manageGrants', $courseGrant->course);

        // An owner must not remove the last owner and strand the course.
        $isLastOwner = $courseGrant->role === CourseGrant::OWNER
            && CourseGrant::where('course_id', $courseGrant->course_id)
                ->where('role', CourseGrant::OWNER)
                ->count() === 1;

        if ($isLastOwner) {
            return back()->with('error', 'A course must keep at least one owner.');
        }

        // Delete on the model instance so the cache-busting event fires.
        $courseGrant->delete();

        return back()->with('success', 'Removed.');
    }
}
