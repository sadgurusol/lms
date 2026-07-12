<?php

namespace Tests\Support;

use App\Models\Client;
use App\Models\ClientKey;
use Firebase\JWT\JWT;

/** An RSA keypair, and the tokens a client would sign with it. */
final class LaunchKeys
{
    public readonly string $privateKey;

    public readonly string $publicKey;

    public function __construct(public readonly string $kid = 'kid-1')
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($resource, $privateKey);

        $this->privateKey = $privateKey;
        $this->publicKey = openssl_pkey_get_details($resource)['key'];
    }

    public function register(Client $client, string $algorithm = 'RS256'): ClientKey
    {
        return ClientKey::create([
            'client_id' => $client->id,
            'kid' => $this->kid,
            'algorithm' => $algorithm,
            'public_key' => $this->publicKey,
            'status' => 'active',
        ]);
    }

    /** @param array<string, mixed> $claims */
    public function sign(array $claims, string $algorithm = 'RS256', ?string $key = null): string
    {
        return JWT::encode($claims, $key ?? $this->privateKey, $algorithm, $this->kid);
    }
}
