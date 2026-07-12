<?php

use App\Authorization\Roles;
use App\Models\Client;
use App\Models\ClientKey;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Who may reach the clients surface
|--------------------------------------------------------------------------
*/

it('lists clients for ops', function () {
    Client::factory()->create(['name' => 'ABC School']);

    $this->actingAs(staff(Roles::OPS))
        ->get('/ops/clients')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('clients/Index')
            ->has('clients', 1)
            ->where('clients.0.name', 'ABC School')
            ->where('can.create', true)
        );
});

it('lets an admin in via the bypass', function () {
    $this->actingAs(staff(Roles::ADMIN))->get('/ops/clients')->assertOk();
});

it('refuses a content author', function () {
    // Authors hold no client permissions.
    $this->actingAs(staff(Roles::CONTENT_AUTHOR))->get('/ops/clients')->assertForbidden();
});

it('sends a guest to the studio login', function () {
    $this->get('/ops/clients')->assertRedirect('/studio/login');
});

/*
|--------------------------------------------------------------------------
| Creating and editing
|--------------------------------------------------------------------------
*/

it('creates a client and derives a slug', function () {
    $this->actingAs(staff(Roles::OPS))
        ->post('/ops/clients', [
            'name' => 'ABC School',
            'slug' => '',
            'status' => 'pending',
            'integration' => 'custom_jwt',
            'contact_email' => 'it@abc.edu',
        ])
        ->assertRedirect();

    $client = Client::sole();
    expect($client->name)->toBe('ABC School')
        ->and($client->slug)->toBe('abc-school')
        ->and($client->status)->toBe('pending')
        ->and($client->integration)->toBe('custom_jwt');
});

it('rejects a duplicate slug', function () {
    Client::factory()->create(['slug' => 'abc-school']);

    $this->actingAs(staff(Roles::OPS))
        ->from('/ops/clients')
        ->post('/ops/clients', [
            'name' => 'Another', 'slug' => 'abc-school',
            'status' => 'pending', 'integration' => 'none',
        ])
        ->assertSessionHasErrors('slug');

    expect(Client::count())->toBe(1);
});

it('rejects an invalid status', function () {
    $this->actingAs(staff(Roles::OPS))
        ->from('/ops/clients')
        ->post('/ops/clients', [
            'name' => 'X', 'status' => 'wobbly', 'integration' => 'none',
        ])
        ->assertSessionHasErrors('status');
});

it('updates a client', function () {
    $client = Client::factory()->create(['status' => 'pending']);

    $this->actingAs(staff(Roles::OPS))
        ->from("/ops/clients/{$client->id}")
        ->patch("/ops/clients/{$client->id}", [
            'name' => 'ABC School (renamed)',
            'slug' => $client->slug,
            'status' => 'active',
            'integration' => 'lti_1_3',
            'contact_email' => null,
        ])
        ->assertSessionHas('success');

    $client->refresh();
    expect($client->name)->toBe('ABC School (renamed)')
        ->and($client->status)->toBe('active')
        ->and($client->integration)->toBe('lti_1_3');
});

/*
|--------------------------------------------------------------------------
| The detail view — and the secret that must never leak
|--------------------------------------------------------------------------
*/

it('shows keys and entitlements on the detail page', function () {
    $client = Client::factory()->create();
    ClientKey::create([
        'client_id' => $client->id,
        'kid' => 'key-1',
        'algorithm' => 'RS256',
        'jwks_url' => 'https://abc.edu/.well-known/jwks.json',
        'status' => 'active',
    ]);

    $this->actingAs(staff(Roles::OPS))
        ->get("/ops/clients/{$client->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('clients/Show')
            ->where('client.name', $client->name)
            ->has('keys', 1)
            ->where('keys.0.kid', 'key-1')
            ->has('entitlements', 0)
            ->where('can.manage', true)
        );
});

/** The webhook secret signs reports back to the client; it must never round-trip. */
it('never exposes the webhook secret', function () {
    $client = Client::factory()->create();
    $client->forceFill(['webhook_secret' => 'super-secret-value'])->save();

    $response = $this->actingAs(staff(Roles::OPS))->get("/ops/clients/{$client->id}")->assertOk();

    expect($response->getContent())->not->toContain('super-secret-value');
});
