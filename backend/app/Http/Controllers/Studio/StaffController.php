<?php

namespace App\Http\Controllers\Studio;

use App\Authorization\Permissions;
use App\Authorization\Roles;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\StaffInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff management: invite people into the studio, set their role, and
 * activate/suspend them. Admin-only (gated on user.manage, which only admin
 * holds via the Gate::before bypass). A new member is emailed an invitation to
 * set their own password — no shared secrets.
 */
class StaffController extends Controller
{
    /** The roles a staff member can be given here (never `learner`). */
    private const ASSIGNABLE_ROLES = [
        Roles::ADMIN,
        Roles::OPS,
        Roles::CONTENT_AUTHOR,
        Roles::CONTENT_REVIEWER,
        Roles::INSTRUCTOR,
    ];

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can(Permissions::USER_MANAGE), 403);

        $staff = User::query()
            ->role(self::ASSIGNABLE_ROLES)
            ->with('roles:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
                'roles' => $user->getRoleNames()->all(),
                'is_self' => $user->id === $request->user()->id,
            ]);

        return Inertia::render('users/Index', [
            'staff' => $staff,
            'roles' => self::ASSIGNABLE_ROLES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can(Permissions::USER_MANAGE), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:200', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(self::ASSIGNABLE_ROLES)],
        ]);

        $user = DB::transaction(function () use ($data) {
            // An unusable random password: the account exists but cannot sign in
            // until the invitee sets their own via the emailed link.
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Str::random(40),
                'status' => User::STATUS_INVITED,
                'kind' => User::KIND_LOCAL,
            ]);
            $user->assignRole($data['role']);

            return $user;
        });

        $this->sendInvite($user);

        return back()->with('success', "Invitation sent to {$user->email}.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->can(Permissions::USER_MANAGE), 403);

        $data = $request->validate([
            'role' => ['sometimes', Rule::in(self::ASSIGNABLE_ROLES)],
            'status' => ['sometimes', Rule::in([User::STATUS_ACTIVE, User::STATUS_SUSPENDED])],
        ]);

        // Never lock the studio out of its last administrator.
        $losesAdmin = (isset($data['role']) && $data['role'] !== Roles::ADMIN)
            || (isset($data['status']) && $data['status'] !== User::STATUS_ACTIVE);

        if ($losesAdmin && $this->isLastActiveAdmin($user)) {
            return back()->with('error', 'This is the last active administrator and cannot be changed.');
        }

        if (isset($data['status'])) {
            $user->update(['status' => $data['status']]);
        }

        if (isset($data['role'])) {
            $user->syncRoles([$data['role']]);
        }

        return back()->with('success', 'Staff member updated.');
    }

    /** Resend the invitation to someone who has not yet accepted. */
    public function invite(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->can(Permissions::USER_MANAGE), 403);
        abort_if($user->status === User::STATUS_ACTIVE, 422, 'This person has already set their password.');

        $this->sendInvite($user);

        return back()->with('success', "Invitation resent to {$user->email}.");
    }

    private function sendInvite(User $user): void
    {
        $token = Password::broker()->createToken($user);
        $user->notify(new StaffInvitation($token));
    }

    private function isLastActiveAdmin(User $user): bool
    {
        if (! $user->hasRole(Roles::ADMIN) || $user->status !== User::STATUS_ACTIVE) {
            return false;
        }

        return User::role(Roles::ADMIN)->where('status', User::STATUS_ACTIVE)->count() <= 1;
    }
}
