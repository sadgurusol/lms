<?php

namespace App\Launch;

use App\Models\Client;
use App\Models\ClientKey;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Resolves the verification keys for a client.
 *
 * Two rules that are not negotiable:
 *
 *  1. The algorithm is **pinned from our record of the key**, never read from
 *     the token header. Otherwise an attacker picks the algorithm — `alg: none`,
 *     or `HS256` with our own public key as the HMAC secret.
 *  2. Only `RS256`/`ES256`. A symmetric algorithm would make our verification
 *     key a signing key, and a leaked secret forges any student.
 */
final class ClientKeyResolver
{
    private const JWKS_TTL_SECONDS = 600;

    /** @return array<string, Key> keyed by kid */
    public function keysFor(Client $client): array
    {
        $keys = [];

        foreach ($client->keys()->usable()->get() as $record) {
            foreach ($this->materialise($record) as $kid => $key) {
                $keys[$kid] = $key;
            }
        }

        if ($keys === []) {
            throw InvalidLaunch::because('no_usable_key', "Client [{$client->slug}] has no usable signing key.");
        }

        return $keys;
    }

    /** @return array<string, Key> */
    private function materialise(ClientKey $record): array
    {
        if ($record->public_key !== null) {
            // The algorithm comes from our row, not from the token.
            return [$record->kid => new Key($record->public_key, $record->algorithm)];
        }

        return $this->fromJwks($record);
    }

    /** @return array<string, Key> */
    private function fromJwks(ClientKey $record): array
    {
        $jwks = Cache::remember(
            'jwks:'.$record->id,
            self::JWKS_TTL_SECONDS,
            fn () => Http::timeout(5)->get($record->jwks_url)->throw()->json(),
        );

        // JWK::parseKeySet reads `alg` from the JWKS, which the client controls
        // — but the client also controls the key, so that is the same trust
        // boundary. Refuse anything symmetric regardless.
        $parsed = JWK::parseKeySet($jwks, $record->algorithm);

        foreach ($parsed as $key) {
            if (! in_array($key->getAlgorithm(), ['RS256', 'ES256'], true)) {
                throw InvalidLaunch::because(
                    'symmetric_algorithm',
                    "Client key set for [{$record->kid}] offers a symmetric algorithm."
                );
            }
        }

        return $parsed;
    }
}
