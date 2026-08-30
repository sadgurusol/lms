<?php

namespace App\Http\Controllers\Portal;

use App\Authorization\Roles;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\PortalVerifyEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Learner sign-in for the public portal — session-cookie auth for the same-origin
 * SPA (not the bearer tokens the mobile app uses). A learner session can never
 * reach the studio: EnsureStaff blocks any non-staff role.
 */
class AuthController extends Controller
{
    /** The signed-in learner, or null. */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->shape($request->user())]);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:200', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'], // hashed by the model cast
                'status' => User::STATUS_ACTIVE,
                'kind' => User::KIND_LOCAL,
            ]);
            $user->assignRole(Roles::LEARNER);

            return $user;
        });

        $user->notify(new PortalVerifyEmail());

        Auth::login($user, true);
        $request->session()->regenerate();

        return response()->json(['user' => $this->shape($user)], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt(['email' => $data['email'], 'password' => $data['password']], true)) {
            throw ValidationException::withMessages(['email' => 'Those credentials don’t match our records.']);
        }

        /** @var User $user */
        $user = $request->user();
        if (! $user->isActive()) {
            Auth::guard('web')->logout();
            throw ValidationException::withMessages(['email' => 'This account is not active.']);
        }

        $request->session()->regenerate();

        return response()->json(['user' => $this->shape($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed>|null */
    private function shape(?User $user): ?array
    {
        return $user === null ? null : [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->hasVerifiedEmail(),
        ];
    }
}
