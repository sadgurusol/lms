<?php

use App\Authorization\Roles;
use App\Models\Client;
use App\Models\ClientKey;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** A valid RSA public key in PEM, generated once for these tests. */
function rsaPublicKeyPem(): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $private);

    return openssl_pkey_get_details(openssl_pkey_get_private($private))['key'];
}

/*
|--------------------------------------------------------------------------
| Launch keys
|--------------------------------------------------------------------------
*/

it('registers a PEM public key', function () {
    $client = Client::factory()->create();

    $this->actingAs(staff(Roles::OPS))
        ->from("/ops/clients/{$client->id}")
        ->post("/ops/clients/{$client->id}/keys", [
            'kid' => '2026-key-1',
            'algorithm' => 'RS256',
            'public_key' => rsaPublicKeyPem(),
        ])
        ->assertSessionHas('success');

    $key = ClientKey::sole();
    expect($key->kid)->toBe('2026-key-1')
        ->and($key->algorithm)->toBe('RS256')
        ->and($key->status)->toBe('active')
        ->and($key->public_key)->not->toBeNull();
});

it('registers a JWKS url instead of a key', function () {
    $client = Client::factory()->create();

    $this->actingAs(staff(Roles::OPS))
        ->from("/ops/clients/{$client->id}")
        ->post("/ops/clients/{$client->id}/keys", [
            'kid' => 'jwks-1',
            'algorithm' => 'ES256',
            'jwks_url' => 'https://sis.example.edu/.well-known/jwks.json',
        ])
        ->assertSessionHas('success');

    expect(ClientKey::sole()->jwks_url)->toBe('https://sis.example.edu/.well-known/jwks.json');
});

it('rejects a symmetric algorithm', function () {
    $client = Client::factory()->create();

    $this->actingAs(staff(Roles::OPS))
        ->from("/ops/clients/{$client->id}")
        ->post("/ops/clients/{$client->id}/keys", [
            'kid' => 'k', 'algorithm' => 'HS256', 'public_key' => rsaPublicKeyPem(),
        ])
        ->assertSessionHasErrors('algorithm');

    expect(ClientKey::count())->toBe(0);
});

it('rejects a garbage PEM key', function () {
    $client = Client::factory()->create();

    $this->actingAs(staff(Roles::OPS))
        ->from("/ops/clients/{$client->id}")
        ->post("/ops/clients/{$client->id}/keys", [
            'kid' => 'k', 'algorithm' => 'RS256', 'public_key' => 'not a key',
        ])
        ->assertSessionHasErrors('public_key');
});

it('rejects a key with neither PEM nor JWKS', function () {
    $client = Client::factory()->create();

    $this->actingAs(staff(Roles::OPS))
        ->from("/ops/clients/{$client->id}")
        ->post("/ops/clients/{$client->id}/keys", ['kid' => 'k', 'algorithm' => 'RS256'])
        ->assertSessionHasErrors(['public_key', 'jwks_url']);
});

it('rejects a duplicate kid for the same client', function () {
    $client = Client::factory()->create();
    $client->keys()->create(['kid' => 'dup', 'algorithm' => 'RS256', 'jwks_url' => 'https://a.example/jwks', 'status' => 'active']);

    $this->actingAs(staff(Roles::OPS))
        ->from("/ops/clients/{$client->id}")
        ->post("/ops/clients/{$client->id}/keys", [
            'kid' => 'dup', 'algorithm' => 'RS256', 'jwks_url' => 'https://b.example/jwks',
        ])
        ->assertSessionHasErrors('kid');
});

it('revokes a key', function () {
    $client = Client::factory()->create();
    $key = $client->keys()->create(['kid' => 'k', 'algorithm' => 'RS256', 'jwks_url' => 'https://a.example/jwks', 'status' => 'active']);

    $this->actingAs(staff(Roles::OPS))
        ->from("/ops/clients/{$client->id}")
        ->delete("/ops/client-keys/{$key->id}")
        ->assertSessionHas('success');

    expect($key->fresh()->status)->toBe('revoked');
});

it('refuses key management without the rotate permission', function () {
    $client = Client::factory()->create();

    // A content author holds no client permissions.
    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->post("/ops/clients/{$client->id}/keys", ['kid' => 'k', 'algorithm' => 'RS256', 'jwks_url' => 'https://a.example/jwks'])
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Webhook
|--------------------------------------------------------------------------
*/

it('sets the webhook url', function () {
    $client = Client::factory()->create();

    $this->actingAs(staff(Roles::OPS))
        ->from("/ops/clients/{$client->id}")
        ->patch("/ops/clients/{$client->id}/webhook", ['report_webhook_url' => 'https://sis.example.edu/hook'])
        ->assertSessionHas('success');

    expect($client->fresh()->report_webhook_url)->toBe('https://sis.example.edu/hook');
});

it('refuses a non-https webhook url', function () {
    $client = Client::factory()->create();

    $this->actingAs(staff(Roles::OPS))
        ->from("/ops/clients/{$client->id}")
        ->patch("/ops/clients/{$client->id}/webhook", ['report_webhook_url' => 'http://insecure.example/hook'])
        ->assertSessionHasErrors('report_webhook_url');
});

it('rotates the secret and shows it exactly once', function () {
    $client = Client::factory()->create();

    $response = $this->actingAs(staff(Roles::OPS))
        ->from("/ops/clients/{$client->id}")
        ->post("/ops/clients/{$client->id}/webhook/secret")
        ->assertSessionHas('success');

    $secret = $client->fresh()->webhook_secret;
    expect($secret)->toStartWith('whsec_')
        // Flashed once for the confirmation screen.
        ->and(session('webhook_secret'))->toBe($secret);

    // A subsequent page load must never carry the secret in props.
    $this->actingAs(staff(Roles::OPS))
        ->get("/ops/clients/{$client->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('client.has_webhook_secret', true)
            ->missing('client.webhook_secret'));

    expect($this->get("/ops/clients/{$client->id}")->getContent())->not->toContain($secret);
});

it('refuses secret rotation without the rotate permission', function () {
    $client = Client::factory()->create();

    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->post("/ops/clients/{$client->id}/webhook/secret")
        ->assertForbidden();
});
