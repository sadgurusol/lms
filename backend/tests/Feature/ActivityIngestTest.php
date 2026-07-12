<?php

use App\Entitlements\Grant;
use App\Launch\CustomJwtValidator;
use App\Models\ActivityEvent;
use App\Models\Client;
use App\Models\ClientEntitlement;
use App\Models\ClientEventOutbox;
use App\Models\CompGrant;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\ManageProduct;
use App\Services\Launch\ExchangeTicket;
use App\Services\Launch\HandleLaunch;
use Illuminate\Support\Str;
use Tests\Support\LaunchKeys;

beforeEach(function () {
    config()->set('launch.audience', 'https://api.example.com/api/v1/launch');
    config()->set('launch.web_fallback_url', 'https://learn.example.com');

    [$this->course] = publishedTextbookCourse();
    $this->course->update(['code' => 'ENG-G10']);

    $this->product = Product::factory()->create();
    app(ManageProduct::class)->addCourse($this->product, $this->course);

    $this->client = Client::factory()->create(['slug' => 'abc-school']);
    $this->keys = new LaunchKeys;
    $this->keys->register($this->client);

    ClientEntitlement::create([
        'client_id' => $this->client->id,
        'product_id' => $this->product->id,
        'seat_model' => ClientEntitlement::UNLIMITED,
        'starts_at' => now()->subDay(),
        'status' => 'active',
    ]);
});

function activityEvent(array $overrides = []): array
{
    return [
        'id' => (string) Str::uuid7(),
        'verb' => 'content.viewed',
        'course_id' => test()->course->id,
        'occurred_at' => now()->toIso8601String(),
        'payload' => ['seconds_visible' => 42],
        ...$overrides,
    ];
}

/** Launch from ABC School and return the client-scoped bearer token. */
function launchedToken(string $externalUserId = 'student-88213'): string
{
    $token = test()->keys->sign([
        'iss' => 'abc-school',
        'aud' => 'https://api.example.com/api/v1/launch',
        'sub' => $externalUserId,
        'jti' => (string) Str::uuid7(),
        'iat' => now()->timestamp,
        'exp' => now()->addSeconds(90)->timestamp,
        'nonce' => Str::random(16),
        'role' => 'learner',
        'context' => ['id' => '10-B', 'title' => 'Grade 10 Section B'],
        'resource' => ['resource_link_id' => 'rl-1', 'course_code' => 'ENG-G10'],
    ]);

    $launch = app(CustomJwtValidator::class)->validate($token);
    ['ticket' => $ticket] = app(HandleLaunch::class)->handle($launch);

    app('auth')->forgetGuards();

    return app(ExchangeTicket::class)->handle($ticket)['token']->plainTextToken;
}

/** A B2C learner entitled through a comp grant. */
function b2cLearner(): User
{
    $user = User::factory()->create();

    CompGrant::create([
        'user_id' => $user->id,
        'product_id' => test()->product->id,
        'reason' => CompGrant::REASON_TRIAL,
        'starts_at' => now()->subMinute(),
    ]);

    return $user;
}

/*
|--------------------------------------------------------------------------
| Ingest
|--------------------------------------------------------------------------
*/

it('requires authentication', function () {
    $this->postJson('/api/v1/activity', ['events' => [activityEvent()]])->assertUnauthorized();
});

it('records an event from a b2c learner with no client attribution', function () {
    $this->actingAs(b2cLearner())
        ->postJson('/api/v1/activity', ['events' => [activityEvent()]])
        ->assertStatus(202)
        ->assertJsonPath('results.0.status', 'accepted');

    $event = ActivityEvent::firstOrFail();

    expect($event->client_id)->toBeNull()
        ->and($event->client_user_id)->toBeNull()
        ->and($event->grant_source)->toBe(Grant::SOURCE_COMP)
        ->and($event->isClientAttributed())->toBeFalse()
        // Nothing is queued for anybody.
        ->and(ClientEventOutbox::count())->toBe(0);
});

it('stamps a launched session with its client and queues it for delivery', function () {
    $this->withToken(launchedToken())
        ->postJson('/api/v1/activity', ['events' => [activityEvent()]])
        ->assertStatus(202)
        ->assertJsonPath('results.0.status', 'accepted');

    $event = ActivityEvent::firstOrFail();

    expect($event->client_id)->toBe($this->client->id)
        ->and($event->client_user_id)->not->toBeNull()
        ->and($event->launch_session_id)->not->toBeNull()
        ->and($event->client_context_id)->not->toBeNull()
        ->and($event->grant_source)->toBe(Grant::SOURCE_CLIENT);

    $queued = ClientEventOutbox::firstOrFail();
    expect($queued->client_id)->toBe($this->client->id)
        ->and($queued->sequence)->toBe(1)
        ->and($queued->event_id)->toBe($event->id);
});

/**
 * The client context comes from the access token. A learner who names a client
 * in the request body must not thereby file their activity under that school.
 */
it('ignores a client id supplied in the event body', function () {
    $other = Client::factory()->create(['slug' => 'xyz-school']);

    $this->actingAs(b2cLearner())
        ->postJson('/api/v1/activity', ['events' => [
            activityEvent(['client_id' => $other->id]),
        ]])
        ->assertStatus(202);

    expect(ActivityEvent::firstOrFail()->client_id)->toBeNull()
        ->and(ClientEventOutbox::count())->toBe(0);
});

it('rejects an event for a course the learner is not entitled to', function () {
    $stranger = User::factory()->create();

    $response = $this->actingAs($stranger)
        ->postJson('/api/v1/activity', ['events' => [activityEvent()]])
        ->assertStatus(202);

    expect($response->json('results.0.status'))->toBe('rejected')
        ->and($response->json('results.0.reason'))->toContain('not entitled')
        ->and(ActivityEvent::count())->toBe(0);
});

it('rejects an unknown verb at validation', function () {
    $this->actingAs(b2cLearner())
        ->postJson('/api/v1/activity', ['events' => [activityEvent(['verb' => 'content.enjoyed'])]])
        ->assertStatus(422);
});

it('accepts the good events in a batch and rejects only the bad one', function () {
    $response = $this->actingAs(b2cLearner())
        ->postJson('/api/v1/activity', ['events' => [
            activityEvent(),
            activityEvent(['course_id' => Str::uuid7()->toString()]),
            activityEvent(),
        ]])
        ->assertStatus(202);

    expect($response->json('results.0.status'))->toBe('accepted')
        ->and($response->json('results.1.status'))->toBe('rejected')
        ->and($response->json('results.2.status'))->toBe('accepted')
        ->and(ActivityEvent::count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Idempotency
|--------------------------------------------------------------------------
*/

/** The client replays its outbox after a crash. Replay must be free. */
it('treats a replayed event id as a duplicate', function () {
    $event = activityEvent();
    $learner = b2cLearner();

    $this->actingAs($learner)->postJson('/api/v1/activity', ['events' => [$event]])
        ->assertJsonPath('results.0.status', 'accepted');

    $this->actingAs($learner)->postJson('/api/v1/activity', ['events' => [$event]])
        ->assertJsonPath('results.0.status', 'duplicate');

    expect(ActivityEvent::count())->toBe(1);
});

/**
 * A replayed event must not be queued a second time, or the client's sequence
 * would contain the same event twice and their reconciliation would be wrong.
 */
it('does not queue a replayed event twice', function () {
    $token = launchedToken();
    $event = activityEvent();

    $this->withToken($token)->postJson('/api/v1/activity', ['events' => [$event]]);
    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/v1/activity', ['events' => [$event]]);

    expect(ActivityEvent::count())->toBe(1)
        ->and(ClientEventOutbox::count())->toBe(1);
});

it('clamps a device clock from the future', function () {
    $this->actingAs(b2cLearner())
        ->postJson('/api/v1/activity', ['events' => [
            activityEvent(['occurred_at' => now()->addYear()->toIso8601String()]),
        ]])
        ->assertStatus(202);

    expect(ActivityEvent::firstOrFail()->occurred_at->isBefore(now()->addMinutes(6)))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The gapless sequence
|--------------------------------------------------------------------------
*/

it('assigns a gapless monotonic sequence per client', function () {
    $token = launchedToken();

    foreach (range(1, 5) as $i) {
        app('auth')->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/activity', ['events' => [activityEvent()]])
            ->assertStatus(202);
    }

    expect(ClientEventOutbox::orderBy('sequence')->pluck('sequence')->all())
        ->toBe([1, 2, 3, 4, 5]);
});

it('keeps each client s sequence independent', function () {
    $abcToken = launchedToken();
    $this->withToken($abcToken)->postJson('/api/v1/activity', ['events' => [activityEvent()]]);
    app('auth')->forgetGuards();
    $this->withToken($abcToken)->postJson('/api/v1/activity', ['events' => [activityEvent()]]);

    // A second client, launched independently.
    $xyz = Client::factory()->create(['slug' => 'xyz-school']);
    $xyzKeys = new LaunchKeys;
    $xyzKeys->register($xyz);

    ClientEntitlement::create([
        'client_id' => $xyz->id,
        'product_id' => $this->product->id,
        'seat_model' => ClientEntitlement::UNLIMITED,
        'starts_at' => now()->subDay(),
        'status' => 'active',
    ]);

    $this->keys = $xyzKeys;
    $this->client = $xyz;
    app('auth')->forgetGuards();

    $xyzToken = (function () use ($xyzKeys) {
        $token = $xyzKeys->sign([
            'iss' => 'xyz-school',
            'aud' => 'https://api.example.com/api/v1/launch',
            'sub' => 'student-1',
            'jti' => (string) Str::uuid7(),
            'iat' => now()->timestamp,
            'exp' => now()->addSeconds(90)->timestamp,
            'nonce' => Str::random(16),
            'role' => 'learner',
            'resource' => ['resource_link_id' => 'rl-x', 'course_code' => 'ENG-G10'],
        ]);

        $launch = app(CustomJwtValidator::class)->validate($token);
        ['ticket' => $ticket] = app(HandleLaunch::class)->handle($launch);
        app('auth')->forgetGuards();

        return app(ExchangeTicket::class)->handle($ticket)['token']->plainTextToken;
    })();

    app('auth')->forgetGuards();
    $this->withToken($xyzToken)->postJson('/api/v1/activity', ['events' => [activityEvent()]])
        ->assertStatus(202);

    expect(ClientEventOutbox::where('client_id', $this->client->id)->pluck('sequence')->all())->toBe([1]);
});
