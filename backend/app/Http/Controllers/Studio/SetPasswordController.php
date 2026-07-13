<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where a staff invitation lands: set a password against the broker token, which
 * activates the account and signs the person in. Also serves "forgot password"
 * once that link is added — same token mechanism.
 */
class SetPasswordController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        return Inertia::render('auth/SetPassword', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                // The `hashed` cast hashes on save; activate on first set.
                $user->forceFill([
                    'password' => $password,
                    'status' => User::STATUS_ACTIVE,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        // Sign them straight in — no need to bounce through the login form.
        $user = User::where('email', $request->string('email'))->firstOrFail();
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('studio.dashboard')->with('success', 'Welcome — your password is set.');
    }
}
