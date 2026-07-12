<?php

use App\Entitlements\EntitlementResolver;
use App\Http\Middleware\EnsureClientScope;
use App\Models\Client;
use App\Models\ClientEntitlement;
use App\Models\ClientSeatAssignment;
use App\Models\ClientUser;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\ManageProduct;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A route added six months from now by someone who has not read the middleware's
 * docblock is the actual threat. This test enumerates the client route group and
 * fails if any route in it lacks client scoping.
 *
 * It is the cheapest security control in the system.
 */
it('scopes every route in the client group', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/client'));

    expect($routes)->not->toBeEmpty('the client route group has disappeared');

    foreach ($routes as $route) {
        $middleware = $route->gatherMiddleware();

        expect(in_array('client.scope', $middleware, true))
            ->toBeTrue("route [{$route->uri()}] is not client-scoped");

        expect(in_array('auth:sanctum', $middleware, true))
            ->toBeTrue("route [{$route->uri()}] is not authenticated");
    }
});

it('is the middleware the alias points at', function () {
    expect(app('router')->getMiddleware()['client.scope'])->toBe(EnsureClientScope::class);
});

/*
|--------------------------------------------------------------------------
| The middleware
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    [$this->course] = publishedTextbookCourse();

    $this->abc = Client::factory()->create(['slug' => 'abc-school', 'name' => 'ABC School']);
    $this->xyz = Client::factory()->create(['slug' => 'xyz-school', 'name' => 'XYZ School']);

    $this->product = Product::factory()->create();
    app(ManageProduct::class)->addCourse($this->product, $this->course);
});

/**
 * Authenticate the next request as this token's owner.
 *
 * forgetGuards() is not optional: the sanctum guard memoises the resolved user
 * on a container-resolved instance, so a second withToken() inside one test
 * would silently keep authenticating as the first user — and a cross-client
 * isolation test would pass while proving nothing.
 */
function asClient(string $token): TestCase
{
    app('auth')->forgetGuards();

    return test()->withToken($token);
}

/** Provision a client user and return a token scoped to that client. */
function scopedToken(Client $client, string $role = ClientUser::LEARNER): string
{
    $user = User::factory()->clientProvisioned()->create();

    ClientUser::create([
        'client_id' => $client->id,
        'external_user_id' => 'ext-'.Str::random(6),
        'user_id' => $user->id,
        'role' => $role,
        'status' => 'active',
    ]);

    $token = $user->createToken('launch-test');
    $token->accessToken->forceFill(['client_id' => $client->id])->save();

    return $token->plainTextToken;
}

it('refuses a session with no client context', function () {
    $b2c = User::factory()->create();

    $this->actingAs($b2c)
        ->getJson('/api/v1/client/roster')
        ->assertForbidden();
});

it('refuses a session whose client has been suspended', function () {
    $token = scopedToken($this->abc, ClientUser::CLIENT_ADMIN);
    $this->abc->update(['status' => 'suspended']);

    $this->withToken($token)->getJson('/api/v1/client/roster')->assertForbidden();
});

it('refuses the console to a learner', function () {
    $this->withToken(scopedToken($this->abc))
        ->getJson('/api/v1/client/roster')
        ->assertForbidden();
});

it('serves the roster to a client admin', function () {
    $token = scopedToken($this->abc, ClientUser::CLIENT_ADMIN);
    scopedToken($this->abc);   // a learner on the same roster

    $this->withToken($token)
        ->getJson('/api/v1/client/roster')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

/**
 * The failure mode to fear is not "school A edits school B's course". It is
 * "school A's report contains school B's students".
 */
it('never shows one client the other client s roster', function () {
    $abcAdmin = scopedToken($this->abc, ClientUser::CLIENT_ADMIN);
    scopedToken($this->xyz);
    scopedToken($this->xyz);

    $abcRoster = asClient($abcAdmin)->getJson('/api/v1/client/roster')->assertOk()->json('data');

    expect($abcRoster)->toHaveCount(1)
        ->and(collect($abcRoster)->pluck('external_user_id'))->not->toBeEmpty();

    // And XYZ's admin sees only XYZ: two learners plus themselves.
    $xyzAdmin = scopedToken($this->xyz, ClientUser::CLIENT_ADMIN);

    $xyzRoster = asClient($xyzAdmin)->getJson('/api/v1/client/roster')->assertOk()->json('data');

    expect($xyzRoster)->toHaveCount(3);

    // No external id appears in both rosters.
    expect(array_intersect(
        array_column($abcRoster, 'external_user_id'),
        array_column($xyzRoster, 'external_user_id'),
    ))->toBe([]);
});

/** The client id comes from the token. A request parameter must not override it. */
it('ignores a client id supplied in the request', function () {
    $abcAdmin = scopedToken($this->abc, ClientUser::CLIENT_ADMIN);
    scopedToken($this->xyz);

    $response = asClient($abcAdmin)
        ->getJson('/api/v1/client/roster?client_id='.$this->xyz->id)
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Seats
|--------------------------------------------------------------------------
*/

function entitleClient(Client $client, Product $product, string $seatModel, ?int $limit = null): ClientEntitlement
{
    return ClientEntitlement::create([
        'client_id' => $client->id,
        'product_id' => $product->id,
        'seat_model' => $seatModel,
        'seat_limit' => $limit,
        'starts_at' => now()->subDay(),
        'status' => 'active',
    ]);
}

/**
 * A student locked out of their coursework because their school under-purchased
 * is a support escalation, and the school will pay anyway. Allow the read, flag
 * the overage, invoice.
 */
it('soft-enforces the active seat model, reporting overage rather than locking out', function () {
    $entitlement = entitleClient($this->abc, $this->product, ClientEntitlement::ACTIVE_SEATS, limit: 1);
    $resolver = app(EntitlementResolver::class);

    $users = collect(range(1, 3))->map(function (int $i) {
        $user = User::factory()->clientProvisioned()->create();
        ClientUser::create([
            'client_id' => $this->abc->id,
            'external_user_id' => "student-{$i}",
            'user_id' => $user->id,
            'role' => ClientUser::LEARNER,
            'status' => 'active',
            'last_seen_at' => now(),
        ]);

        return $user;
    });

    foreach ($users as $user) {
        expect($resolver->entitles($user, $this->course, $this->abc->id))->toBeTrue();
    }

    expect($entitlement->fresh()->seatsUsed())->toBe(3)
        ->and($entitlement->fresh()->isOverSeats())->toBeTrue();
});

/**
 * `assigned` is different: assignment is an explicit administrative act, so the
 * refusal points at someone who can fix it.
 */
it('hard-enforces the assigned seat model', function () {
    $entitlement = entitleClient($this->abc, $this->product, ClientEntitlement::ASSIGNED, limit: 1);
    $resolver = app(EntitlementResolver::class);

    $user = User::factory()->clientProvisioned()->create();
    $membership = ClientUser::create([
        'client_id' => $this->abc->id,
        'external_user_id' => 'student-1',
        'user_id' => $user->id,
        'role' => ClientUser::LEARNER,
        'status' => 'active',
    ]);

    expect($resolver->entitles($user, $this->course, $this->abc->id))->toBeFalse();

    ClientSeatAssignment::create([
        'client_entitlement_id' => $entitlement->id,
        'client_user_id' => $membership->id,
    ]);

    expect($resolver->entitles($user->fresh(), $this->course, $this->abc->id))->toBeTrue();
});

it('revokes access when a named seat is released', function () {
    $entitlement = entitleClient($this->abc, $this->product, ClientEntitlement::ASSIGNED, limit: 5);
    $resolver = app(EntitlementResolver::class);

    $user = User::factory()->clientProvisioned()->create();
    $membership = ClientUser::create([
        'client_id' => $this->abc->id, 'external_user_id' => 's1',
        'user_id' => $user->id, 'role' => ClientUser::LEARNER, 'status' => 'active',
    ]);

    $seat = ClientSeatAssignment::create([
        'client_entitlement_id' => $entitlement->id,
        'client_user_id' => $membership->id,
    ]);

    expect($resolver->entitles($user, $this->course, $this->abc->id))->toBeTrue();

    // No manual cache bust: the model does it.
    $seat->update(['released_at' => now()]);

    expect($resolver->entitles($user, $this->course, $this->abc->id))->toBeFalse();
});

it('reports seats through the client console', function () {
    entitleClient($this->abc, $this->product, ClientEntitlement::ACTIVE_SEATS, limit: 2);
    $token = scopedToken($this->abc, ClientUser::CLIENT_ADMIN);

    $this->withToken($token)
        ->getJson('/api/v1/client/seats')
        ->assertOk()
        ->assertJsonPath('data.0.seat_limit', 2)
        ->assertJsonPath('data.0.over_seats', false);
});

it('refuses a seat limit on a limited model', function () {
    expectDatabaseRejection(
        fn () => entitleClient($this->abc, $this->product, ClientEntitlement::ACTIVE_SEATS, limit: null),
        'client_entitlements_seat_limit_check',
    );
});
