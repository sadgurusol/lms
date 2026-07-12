<?php

namespace App\Http\Controllers\Ops;

use App\Authorization\Permissions;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A client's launch-verification keys. The LMS holds the client's *public* key
 * and verifies the JWTs their SIS signs on launch. Only asymmetric algorithms —
 * a symmetric secret here would be a signing key, and a leak would let anyone
 * forge a launch for any student.
 */
class ClientKeyController extends Controller
{
    public function store(Request $request, Client $client): RedirectResponse
    {
        abort_unless($request->user()->can(Permissions::CLIENT_KEY_ROTATE), 403);

        $data = $request->validate([
            'kid' => [
                'required', 'string', 'max:120',
                Rule::unique('client_keys', 'kid')->where('client_id', $client->id),
            ],
            'algorithm' => ['required', Rule::in(['RS256', 'ES256'])],
            // Exactly one source of key material — a PEM public key or a JWKS URL.
            'public_key' => ['nullable', 'required_without:jwks_url', 'string'],
            'jwks_url' => ['nullable', 'required_without:public_key', 'string', 'url', 'starts_with:https://'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        if (($data['public_key'] ?? null) !== null && openssl_pkey_get_public($data['public_key']) === false) {
            return back()->withErrors(['public_key' => 'That does not parse as a PEM public key.']);
        }

        $client->keys()->create([
            'kid' => $data['kid'],
            'algorithm' => $data['algorithm'],
            'public_key' => $data['public_key'] ?? null,
            'jwks_url' => $data['jwks_url'] ?? null,
            'status' => 'active',
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        return back()->with('success', "Added launch key “{$data['kid']}”.");
    }

    public function revoke(Request $request, ClientKey $clientKey): RedirectResponse
    {
        abort_unless($request->user()->can(Permissions::CLIENT_KEY_ROTATE), 403);

        // Revoke, don't delete: the kid may appear in the logs of launches it
        // once verified, and scopeUsable already excludes a revoked key.
        $clientKey->update(['status' => 'revoked']);

        return back()->with('success', 'Launch key revoked.');
    }
}
