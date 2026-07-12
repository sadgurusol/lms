<?php

use App\Entitlements\EntitlementResolver;
use App\Entitlements\Grant;
use App\Launch\CustomJwtValidator;
use App\Launch\InvalidLaunch;
use App\Models\Client;
use App\Models\ClientContext;
use App\Models\ClientEntitlement;
use App\Models\LaunchSession;
use App\Models\LaunchTicket;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ResourceLink;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Catalog\ManageProduct;
use App\Services\Launch\ExchangeTicket;
use App\Services\Launch\HandleLaunch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\LaunchKeys;

beforeEach(function () {
    config()->set('launch.audience', 'https://api.example.com/api/v1/launch');
    config()->set('launch.web_fallback_url', 'https://learn.example.com');

    [$this->course] = publishedTextbookCourse();
    $this->course->update(['code' => 'ENG-G10']);

    $this->client = Client::factory()->create(['slug' => 'abc-school']);
    $this->keys = new LaunchKeys;
    $this->keys->register($this->client);

    $this->product = Product::factory()->create();
    app(ManageProduct::class)->addCourse($this->product, $this->course);

    ClientEntitlement::create([
        'client_id' => $this->client->id,
        'product_id' => $this->product->id,
        'seat_model' => ClientEntitlement::UNLIMITED,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addYear(),
        'status' => 'active',
    ]);
});

function launchToken(array $overrides = []): string
{
    return test()->keys->sign([
        'iss' => 'abc-school',
        'aud' => 'https://api.example.com/api/v1/launch',
        'sub' => 'student-88213',
        'jti' => (string) Str::uuid7(),
        'iat' => now()->timestamp,
        'exp' => now()->addSeconds(90)->timestamp,
        'nonce' => Str::random(16),
        'name' => 'R. Sharma',
        'role' => 'learner',
        'context' => ['id' => '10-B', 'title' => 'Grade 10 Section B'],
        'resource' => ['resource_link_id' => 'rl-chapter-3', 'course_code' => 'ENG-G10'],
        ...$overrides,
    ]);
}

function doLaunch(array $overrides = []): array
{
    $launch = app(CustomJwtValidator::class)->validate(launchToken($overrides));

    return app(HandleLaunch::class)->handle($launch, '203.0.113.4', 'Mozilla/5.0');
}

/*
|--------------------------------------------------------------------------
| The redirect carries a ticket, never a token
|--------------------------------------------------------------------------
*/

it('redirects to a universal link carrying an opaque ticket', function () {
    $response = $this->post('/api/v1/launch', ['launch_token' => launchToken()]);

    $response->assertRedirect();
    $location = $response->headers->get('Location');

    expect($location)->toStartWith('https://learn.example.com/l/');

    $ticket = Str::afterLast($location, '/');

    // The URL lands in browser history, access logs and Referer headers. What it
    // carries must be worthless once used.
    expect($ticket)->toHaveLength(64)
        ->and(LaunchTicket::where('token_hash', LaunchTicket::hash($ticket))->exists())->toBeTrue();

    // And the plaintext ticket is never stored.
    expect(DB::table('launch_tickets')->where('token_hash', $ticket)->exists())->toBeFalse();
});

it('exchanges a ticket for a client-scoped token', function () {
    ['ticket' => $ticket, 'session' => $session] = doLaunch();

    $response = $this->postJson('/api/v1/auth/launch/exchange', ['ticket' => $ticket])
        ->assertOk()
        ->assertJsonPath('launch_context.client_id', $this->client->id)
        ->assertJsonPath('launch_context.role', 'learner')
        ->assertJsonPath('deep_link.course_id', $this->course->id);

    $plain = $response->json('access_token');
    $token = PersonalAccessToken::findToken($plain);

    // The client context travels with the token, not with the request.
    expect($token->client_id)->toBe($this->client->id)
        ->and($token->launch_session_id)->toBe($session->id)
        ->and($token->abilities)->toContain('attempt.take')
        ->and($session->fresh()->exchanged_at)->not->toBeNull();
});

/** A double-click on the redirect must not mint two sessions. */
it('burns a ticket on first use', function () {
    ['ticket' => $ticket] = doLaunch();

    $this->postJson('/api/v1/auth/launch/exchange', ['ticket' => $ticket])->assertOk();

    $this->postJson('/api/v1/auth/launch/exchange', ['ticket' => $ticket])
        ->assertStatus(401)
        ->assertJsonPath('reason', 'bad_ticket');

    expect(PersonalAccessToken::count())->toBe(1);
});

it('refuses an expired ticket', function () {
    ['ticket' => $ticket] = doLaunch();

    $this->travel(HandleLaunch::TICKET_TTL_SECONDS + 5)->seconds();

    $this->postJson('/api/v1/auth/launch/exchange', ['ticket' => $ticket])
        ->assertStatus(401)
        ->assertJsonPath('reason', 'bad_ticket');
});

it('refuses a ticket nobody issued', function () {
    expect(fn () => app(ExchangeTicket::class)->handle(Str::random(64)))
        ->toThrow(InvalidLaunch::class, 'expired or has already been used');
});

/*
|--------------------------------------------------------------------------
| Durable replay defence
|--------------------------------------------------------------------------
*/

/**
 * Redis is the fast path; the unique index on (client_id, jti) is the truth.
 * Flushing the cache must not reopen the replay window.
 */
it('refuses a replayed launch even after the cache is flushed', function () {
    $token = launchToken();

    $launch = app(CustomJwtValidator::class)->validate($token);
    app(HandleLaunch::class)->handle($launch);

    cache()->flush();

    // The token now passes the Redis guard, and the database catches it.
    $replayed = app(CustomJwtValidator::class)->validate($token);

    expect(fn () => app(HandleLaunch::class)->handle($replayed))
        ->toThrow(InvalidLaunch::class, 'already been used');

    expect(LaunchSession::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Provisioning from the launch
|--------------------------------------------------------------------------
*/

it('provisions the user, the class and the resource link on first launch', function () {
    ['session' => $session] = doLaunch();

    expect($session->clientUser->external_user_id)->toBe('student-88213')
        ->and($session->clientUser->user->isClientProvisioned())->toBeTrue()
        ->and($session->clientContext ?? $session->client_context_id)->not->toBeNull()
        ->and($session->resourceLink->course_id)->toBe($this->course->id)
        ->and($session->ip)->toBe('203.0.113.4');

    expect(ClientContext::where('external_context_id', '10-B')->exists())->toBeTrue();
});

it('reuses the resource link on later launches', function () {
    doLaunch();
    doLaunch();

    expect(ResourceLink::count())->toBe(1)
        ->and(LaunchSession::count())->toBe(2);
});

it('refuses a launch naming a course we do not have', function () {
    expect(fn () => doLaunch(['resource' => ['course_code' => 'NOPE-999']]))
        ->toThrow(InvalidLaunch::class, 'No course is registered as [NOPE-999]');
});

it('refuses a launch for a deactivated roster member', function () {
    ['session' => $session] = doLaunch();
    $session->clientUser->update(['status' => 'deactivated']);

    expect(fn () => doLaunch())->toThrow(InvalidLaunch::class, 'removed from the roster');
});

/*
|--------------------------------------------------------------------------
| The launched session reads content under the client's contract
|--------------------------------------------------------------------------
*/

it('lets a launched student read the course, attributed to their client', function () {
    ['ticket' => $ticket] = doLaunch();

    $plain = $this->postJson('/api/v1/auth/launch/exchange', ['ticket' => $ticket])->json('access_token');

    $this->withToken($plain)
        ->getJson("/api/v1/me/courses/{$this->course->id}/content")
        ->assertOk();

    $this->withToken($plain)
        ->getJson('/api/v1/me/courses')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

/**
 * Attribution follows the session context, not the cheapest grant. A student who
 * also holds a personal subscription still reads under ABC's contract when they
 * launched from ABC — otherwise ABC's activity report silently omits them.
 */
it('attributes a launched session to the client even when the student also subscribes', function () {
    ['ticket' => $ticket, 'session' => $session] = doLaunch();
    $user = $session->clientUser->user;

    $plan = Plan::factory()->create(['product_id' => $this->product->id]);
    Subscription::factory()->create([
        'user_id' => $user->id,
        'plan_id' => $plan->id,
        'status' => Subscription::ACTIVE,
        'current_period_end' => now()->addMonth(),
    ]);

    $resolver = app(EntitlementResolver::class);

    expect($resolver->grantFor($user, $this->course, $this->client->id)->source)
        ->toBe(Grant::SOURCE_CLIENT);

    // The same human, on a personal login, reads under their own subscription.
    expect($resolver->grantFor($user, $this->course, null)->source)
        ->toBe(Grant::SOURCE_SUBSCRIPTION);
});

/** A B2C login never inherits the school's entitlements. */
it('does not let a personal session borrow the client contract', function () {
    $stranger = User::factory()->create();

    $resolver = app(EntitlementResolver::class);

    expect($resolver->entitles($stranger, $this->course, null))->toBeFalse()
        // Even naming the client explicitly: they are not on its roster.
        ->and($resolver->entitles($stranger, $this->course, $this->client->id))->toBeFalse();
});

it('revokes access the moment a student leaves the roster', function () {
    ['session' => $session] = doLaunch();
    $user = $session->clientUser->user;
    $resolver = app(EntitlementResolver::class);

    expect($resolver->entitles($user, $this->course, $this->client->id))->toBeTrue();

    $session->clientUser->update(['status' => 'deactivated']);

    expect($resolver->entitles($user, $this->course, $this->client->id))->toBeFalse();
});

it('revokes access when the client contract expires', function () {
    ['session' => $session] = doLaunch();
    $user = $session->clientUser->user;
    $resolver = app(EntitlementResolver::class);

    expect($resolver->entitles($user, $this->course, $this->client->id))->toBeTrue();

    $this->travel(400)->days();

    expect($resolver->entitles($user, $this->course, $this->client->id))->toBeFalse();
});

it('revokes access when the client is suspended', function () {
    ['session' => $session] = doLaunch();
    $user = $session->clientUser->user;

    $this->client->update(['status' => 'suspended']);

    expect(app(EntitlementResolver::class)->entitles($user, $this->course, $this->client->id))
        ->toBeFalse();
});
