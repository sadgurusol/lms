<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Notifications\PortalResetPassword;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/** Portal learner password reset (session SPA), via the app's users broker. */
class PasswordController extends Controller
{
    /** Email a reset link. Always reports success so email existence isn't leaked. */
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        Password::broker()->sendResetLink($data, function ($user, string $token) {
            $user->notify(new PortalResetPassword($token));
        });

        return response()->json(['message' => 'If that email is registered, a reset link is on its way.']);
    }

    /** Complete a reset from the emailed token. */
    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::broker()->reset($data, function ($user, string $password) {
            $user->forceFill(['password' => Hash::make($password)])
                ->setRememberToken(Str::random(60))
                ->save();

            event(new PasswordReset($user));
        });

        if ($status === Password::PasswordReset) {
            return response()->json(['message' => 'Password updated. You can now sign in.']);
        }

        throw ValidationException::withMessages(['email' => [__($status)]]);
    }
}
