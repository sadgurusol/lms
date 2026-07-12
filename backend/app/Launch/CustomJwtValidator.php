<?php

namespace App\Launch;

use App\Models\Client;
use Firebase\JWT\JWT;
use Throwable;

/**
 * The launch path for SIS vendors who do not speak LTI 1.3.
 *
 * Same guarantees, smaller surface. Every check below has been a real, exploited
 * vulnerability in some JWT integration somewhere.
 */
final class CustomJwtValidator implements LaunchValidator
{
    /** A launch token is a one-shot credential, not a session. */
    private const MAX_LIFETIME_SECONDS = 120;

    private const CLOCK_SKEW_SECONDS = 60;

    public function __construct(
        private readonly ClientKeyResolver $keys,
        private readonly ReplayGuard $replay,
    ) {}

    public function validate(string $token): LaunchRequest
    {
        $client = $this->clientFor($token);

        JWT::$leeway = self::CLOCK_SKEW_SECONDS;

        try {
            $claims = JWT::decode($token, $this->keys->keysFor($client));
        } catch (InvalidLaunch $e) {
            throw $e;
        } catch (Throwable $e) {
            // Signature, expiry, `alg: none`, unknown kid — php-jwt refuses them
            // all. Do not leak which to the caller.
            throw InvalidLaunch::because('bad_signature', 'The launch token could not be verified.');
        }

        $this->assertAudience($claims);
        $this->assertLifetime($claims);
        $this->assertFresh($client, $claims);

        return new LaunchRequest(
            clientId: $client->id,
            externalUserId: (string) $claims->sub,
            jti: (string) $claims->jti,
            nonce: (string) $claims->nonce,
            messageType: 'CustomLaunchRequest',
            role: $this->mapRole($claims->role ?? null),
            externalName: $claims->name ?? null,
            externalEmail: $claims->email ?? null,
            externalContextId: $claims->context->id ?? null,
            contextTitle: $claims->context->title ?? null,
            externalResourceLinkId: $claims->resource->resource_link_id ?? null,
            courseCode: $claims->resource->course_code ?? null,
            courseNodeId: $claims->resource->node_id ?? null,
        );
    }

    /**
     * Read `iss` from the unverified payload only to find which key to verify
     * with. Nothing is trusted until the signature checks out.
     */
    private function clientFor(string $token): Client
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw InvalidLaunch::because('malformed', 'The launch token is not a JWT.');
        }

        $payload = json_decode(JWT::urlsafeB64Decode($parts[1]));
        $issuer = $payload->iss ?? null;

        if (! is_string($issuer)) {
            throw InvalidLaunch::because('missing_iss', 'The launch token has no issuer.');
        }

        $client = Client::where('slug', $issuer)->first();

        if ($client === null || ! $client->isActive()) {
            throw InvalidLaunch::because('unknown_client', "No active client is registered as [{$issuer}].");
        }

        if ($client->integration !== Client::CUSTOM_JWT) {
            throw InvalidLaunch::because('wrong_integration', "Client [{$issuer}] does not use custom-JWT launch.");
        }

        return $client;
    }

    private function assertAudience(object $claims): void
    {
        $expected = config('launch.audience');
        $audience = (array) ($claims->aud ?? []);

        if (! in_array($expected, $audience, true)) {
            throw InvalidLaunch::because('bad_audience', 'The launch token is not addressed to this service.');
        }
    }

    /**
     * `exp - iat` must be short. A token valid for a day is a bearer credential
     * a client can hand to anyone, forever.
     */
    private function assertLifetime(object $claims): void
    {
        if (! isset($claims->iat, $claims->exp)) {
            throw InvalidLaunch::because('missing_lifetime', 'The launch token has no iat/exp.');
        }

        if ($claims->exp - $claims->iat > self::MAX_LIFETIME_SECONDS) {
            throw InvalidLaunch::because(
                'lifetime_too_long',
                'A launch token may live at most '.self::MAX_LIFETIME_SECONDS.' seconds.'
            );
        }
    }

    private function assertFresh(Client $client, object $claims): void
    {
        foreach (['jti', 'nonce'] as $claim) {
            if (! isset($claims->$claim) || ! is_string($claims->$claim) || $claims->$claim === '') {
                throw InvalidLaunch::because("missing_{$claim}", "The launch token has no {$claim}.");
            }

            if (! $this->replay->claim($client->id, $claim, $claims->$claim)) {
                throw InvalidLaunch::because('replayed', "This launch token has already been used ({$claim}).");
            }
        }
    }

    /**
     * Role mapping is explicit and per-integration, never a pass-through.
     *
     * A client that starts asserting a role string we did not anticipate must
     * not thereby mint administrators. Anything unmapped is a learner.
     */
    private function mapRole(mixed $role): string
    {
        return match ($role) {
            'instructor' => 'instructor',
            'client_admin' => 'client_admin',
            default => 'learner',
        };
    }
}
