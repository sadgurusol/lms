<?php

namespace App\Http\Middleware;

use App\Authorization\Permissions;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * Permissions the studio shell needs in order to decide what to render.
     *
     * Only the coarse, navigation-level ones. Per-course authority
     * (`update`, `review`, `publish` on a specific Course) is answered by
     * CoursePolicy on each request and shipped with that page's props — never
     * re-derived in the client. There is one authorization source.
     */
    private const SHELL_PERMISSIONS = [
        Permissions::SCHEMA_VIEW,
        Permissions::SCHEMA_CREATE,
        Permissions::SCHEMA_PUBLISH,
        Permissions::COURSE_CREATE,
        Permissions::COURSE_VIEW_ANY,
        // The Courses nav link keys off this: an author holds `granted`, not
        // `any`, and without it the whole section is invisible to them.
        Permissions::COURSE_VIEW_GRANTED,
        Permissions::COURSE_PUBLISH,
        Permissions::QUESTION_MANAGE,
        Permissions::MEDIA_UPLOAD,
        Permissions::PRODUCT_VIEW,
        Permissions::CLIENT_VIEW,
        Permissions::AUDIT_VIEW,
        Permissions::USER_MANAGE,
        Permissions::LEARNER_MANAGE,
    ];

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();

        return [
            ...parent::share($request),

            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames()->all(),
                ],
                'can' => $user === null ? [] : $this->abilities($user),
            ],

            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                // A one-time secret (e.g. a rotated webhook key) shown once after
                // its redirect, then gone — it is never a persisted prop.
                'secret' => fn () => $request->session()->get('webhook_secret'),
            ],
        ];
    }

    /** @return array<string, bool> */
    private function abilities(User $user): array
    {
        $can = [];

        foreach (self::SHELL_PERMISSIONS as $permission) {
            $can[$permission] = $user->can($permission);
        }

        return $can;
    }
}
