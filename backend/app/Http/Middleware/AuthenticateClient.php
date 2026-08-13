<?php

namespace App\Http\Middleware;

use App\Launch\ClientKeyResolver;
use App\Models\Client;
use Closure;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Authenticates a B2B partner (client) for the read-only partner API. The client
 * signs a short-lived RS256 JWT with the same key it uses for launch; we verify
 * it, resolve the Client, and expose it as $request->attributes 'client'.
 *
 * Read-only, so no jti/nonce replay guard (unlike a launch).
 */
class AuthenticateClient
{
    public function __construct(private readonly ClientKeyResolver $keys) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (! $token) {
            abort(401, 'Missing client token.');
        }

        $client = $this->clientFor($token);

        JWT::$leeway = 60;
        try {
            $claims = JWT::decode($token, $this->keys->keysFor($client));
        } catch (Throwable) {
            abort(401, 'Invalid client token.');
        }

        $aud = (array) ($claims->aud ?? []);
        if (! in_array(config('launch.partner_audience'), $aud, true)) {
            abort(401, 'Token is not addressed to the partner API.');
        }

        $request->attributes->set('client', $client);

        return $next($request);
    }

    private function clientFor(string $token): Client
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            abort(401, 'Malformed client token.');
        }

        $payload = json_decode(JWT::urlsafeB64Decode($parts[1]));
        $slug = $payload->iss ?? null;

        $client = is_string($slug) ? Client::where('slug', $slug)->first() : null;

        if ($client === null || ! $client->isActive() || $client->integration !== Client::CUSTOM_JWT) {
            abort(401, 'Unknown or inactive client.');
        }

        return $client;
    }
}
