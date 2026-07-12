<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * B2C token auth for the learner app.
 *
 * A B2B learner never comes through here — they hold no password and arrive by
 * launch (see LaunchController). This is only the direct-signup path: email and
 * password in, a bearer token out. The token carries no client context, so
 * EntitlementResolver treats these users as B2C (subscription / purchase / comp).
 */
class AuthController extends Controller
{
    private const TOKEN_TTL_DAYS = 30;

    public function token(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        // One message for a wrong password, an unknown email, and a
        // client-provisioned account (which has no password to check). Anything
        // that distinguishes them is a user-enumeration oracle. Hash::check on a
        // null password fails closed.
        if ($user === null
            || $user->password === null
            || ! Hash::check($credentials['password'], $user->password)
            || ! $user->isActive()) {
            throw ValidationException::withMessages([
                'email' => ['Those credentials do not match our records.'],
            ]);
        }

        $token = $user->createToken(
            $credentials['device_name'] ?? 'learner-app',
            ['*'],
            now()->addDays(self::TOKEN_TTL_DAYS),
        );

        $user->forceFill(['last_seen_at' => now()])->save();

        return response()->json([
            'access_token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_in' => self::TOKEN_TTL_DAYS * 24 * 3600,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        // Revoke just this device's token, not every session the user holds.
        $request->user()->currentAccessToken()->delete();

        return response()->json(['data' => ['revoked' => true]]);
    }
}
