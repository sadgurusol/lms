<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            // One message for both a wrong password and an unknown email.
            // Anything else is a user-enumeration oracle.
            throw ValidationException::withMessages([
                'email' => 'Those credentials do not match our records.',
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        // A client-provisioned user holds no password and cannot reach here.
        // Say so explicitly rather than relying on that.
        if ($user->isClientProvisioned() || ! $user->isActive()) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => 'This account cannot sign in to the studio.',
            ]);
        }

        $request->session()->regenerate();
        $user->forceFill(['last_seen_at' => now()])->save();

        return redirect()->intended(route('studio.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('studio.login');
    }
}
