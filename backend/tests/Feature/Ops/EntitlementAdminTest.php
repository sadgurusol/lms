<?php

use App\Authorization\Roles;
use App\Entitlements\EntitlementCache;
use App\Models\Client;
use App\Models\ClientEntitlement;
use App\Models\Product;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** @return array{Client, Product} */
function clientAndProduct(): array
{
    return [Client::factory()->create(), Product::factory()->create()];
}

/** @return array<string, mixed> */
function entitlementInput(Product $product, array $overrides = []): array
{
    return [
        'product_id' => $product->id,
        'seat_model' => 'active',
        'seat_limit' => 50,
        'starts_at' => '2026-01-01',
        'ends_at' => '2026-12-31',
        'status' => 'active',
        'contract_ref' => 'PO-123',
        ...$overrides,
    ];
}

/*
|--------------------------------------------------------------------------
| Granting
|--------------------------------------------------------------------------
*/

it('grants a product to a client', function () {
    [$client, $product] = clientAndProduct();

    $this->actingAs(staff(Roles::OPS))
        ->from("/ops/clients/{$client->id}")
        ->post("/ops/clients/{$client->id}/entitlements", entitlementInput($product))
        ->assertSessionHas('success');

    $e = ClientEntitlement::sole();
    expect($e->client_id)->toBe($client->id)
        ->and($e->product_id)->toBe($product->id)
        ->and($e->seat_model)->toBe('active')
        ->and($e->seat_limit)->toBe(50);
});

it('forces seat_limit to null for an unlimited grant', function () {
    [$client, $product] = clientAndProduct();

    $this->actingAs(staff(Roles::OPS))
        ->from("/ops/clients/{$client->id}")
        ->post("/ops/clients/{$client->id}/entitlements", entitlementInput($product, [
            'seat_model' => 'unlimited', 'seat_limit' => 999,
        ]))
        ->assertSessionHas('success');

    // Even though a number was sent, unlimited holds none — and the DB CHECK
    // would reject unlimited-with-a-limit anyway.
    expect(ClientEntitlement::sole()->seat_limit)->toBeNull();
});

it('requires a seat limit for a metered model', function () {
    [$client, $product] = clientAndProduct();

    $this->actingAs(staff(Roles::OPS))
        ->from("/ops/clients/{$client->id}")
        ->post("/ops/clients/{$client->id}/entitlements", entitlementInput($product, [
            'seat_model' => 'active', 'seat_limit' => null,
        ]))
        ->assertSessionHasErrors('seat_limit');
});

it('rejects an end date on or before the start', function () {
    [$client, $product] = clientAndProduct();

    $this->actingAs(staff(Roles::OPS))
        ->from("/ops/clients/{$client->id}")
        ->post("/ops/clients/{$client->id}/entitlements", entitlementInput($product, [
            'starts_at' => '2026-06-01', 'ends_at' => '2026-06-01',
        ]))
        ->assertSessionHasErrors('ends_at');
});

it('rejects a duplicate product on the same start date', function () {
    [$client, $product] = clientAndProduct();
    $client->entitlements()->create(entitlementInput($product, ['starts_at' => '2026-01-01']));

    $this->actingAs(staff(Roles::OPS))
        ->from("/ops/clients/{$client->id}")
        ->post("/ops/clients/{$client->id}/entitlements", entitlementInput($product, ['starts_at' => '2026-01-01']))
        ->assertSessionHasErrors('product_id');

    expect(ClientEntitlement::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Editing and removing
|--------------------------------------------------------------------------
*/

it('updates an entitlement without changing its product', function () {
    [$client, $product] = clientAndProduct();
    $e = $client->entitlements()->create(entitlementInput($product));
    $other = Product::factory()->create();

    $this->actingAs(staff(Roles::OPS))
        ->from("/ops/clients/{$client->id}")
        ->patch("/ops/entitlements/{$e->id}", entitlementInput($product, [
            'product_id' => $other->id, // ignored
            'status' => 'suspended',
            'seat_limit' => 80,
        ]))
        ->assertSessionHas('success');

    $e->refresh();
    expect($e->status)->toBe('suspended')
        ->and($e->seat_limit)->toBe(80)
        // Product is immutable on update.
        ->and($e->product_id)->toBe($product->id);
});

it('removes an entitlement', function () {
    [$client, $product] = clientAndProduct();
    $e = $client->entitlements()->create(entitlementInput($product));

    $this->actingAs(staff(Roles::OPS))
        ->from("/ops/clients/{$client->id}")
        ->delete("/ops/entitlements/{$e->id}")
        ->assertSessionHas('success');

    expect(ClientEntitlement::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Cache and authorization
|--------------------------------------------------------------------------
*/

it('busts the entitlement cache when a grant changes', function () {
    [$client, $product] = clientAndProduct();

    // Seed a cache entry, then grant — the version must move so the entry misses.
    $cache = app(EntitlementCache::class);
    $before = $cache->remember('user-x', $client->id, fn () => 'cached');
    expect($before)->toBe('cached');

    $this->actingAs(staff(Roles::OPS))
        ->post("/ops/clients/{$client->id}/entitlements", entitlementInput($product));

    $after = $cache->remember('user-x', $client->id, fn () => 'recomputed');
    expect($after)->toBe('recomputed');
});

it('shows the client with entitlement options and products', function () {
    [$client, $product] = clientAndProduct();
    $client->entitlements()->create(entitlementInput($product));

    $this->actingAs(staff(Roles::OPS))
        ->get("/ops/clients/{$client->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('clients/Show')
            ->has('entitlements', 1)
            ->where('entitlements.0.product', $product->name)
            ->has('products', 1)
            ->where('can.manage_entitlements', true)
            ->has('options.seat_models')
        );
});

it('refuses entitlement writes without the permission', function () {
    [$client, $product] = clientAndProduct();

    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->post("/ops/clients/{$client->id}/entitlements", entitlementInput($product))
        ->assertForbidden();
});
