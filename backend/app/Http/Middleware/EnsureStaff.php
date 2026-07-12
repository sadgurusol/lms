<?php

namespace App\Http\Middleware;

use App\Authorization\Roles;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The studio is for the content provider's own people.
 *
 * A client-provisioned user already cannot log in — they hold no password and no
 * email, enforced by `users_provisioned_has_no_password`. This is the second
 * lock: a learner or an instructor who somehow holds a session must not reach an
 * authoring surface, and neither must a `client_admin`.
 *
 * Cheap, and it means a bug in one lock is not a breach.
 */
class EnsureStaff
{
    private const STAFF_ROLES = [
        Roles::ADMIN,
        Roles::OPS,
        Roles::CONTENT_AUTHOR,
        Roles::CONTENT_REVIEWER,
    ];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        abort_if($user === null, 403);
        abort_if($user->isClientProvisioned(), 403, 'This account cannot access the studio.');
        abort_unless($user->hasAnyRole(self::STAFF_ROLES), 403, 'The studio is for staff.');
        abort_unless($user->isActive(), 403, 'This account is suspended.');

        return $next($request);
    }
}
