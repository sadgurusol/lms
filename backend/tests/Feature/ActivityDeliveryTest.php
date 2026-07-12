<?php

use App\Activity\Verb;
use App\Models\ActivityEvent;
use App\Models\Client;
use App\Models\ClientEventOutbox;
use App\Models\ClientOutboxState;
use App\Models\ClientUser;
use App\Models\CoursePublication;
use App\Models\User;
use App\Services\Activity\ActivitySerialiser;
use App\Services\Activity\DeliverClientEvents;
use App\Services\Activity\ReplayOutbox;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

beforeEach(function () {
    [$this->course] = publishedTextbookCourse();
    $this->publication = CoursePublication::where('course_id', $this->course->id)->firstOrFail();

    $this->client = Client::factory()->create([
        'slug' => 'abc-school',
        'report_webhook_url' => 'https://sis.abcschool.edu/hooks/lms',
        'webhook_secret' => 'whsec_abc',
    ]);

    ClientOutboxState::create(['client_id' => $this->client->id, 'next_sequence' => 1]);
});

/** Queue an event for the client at the next sequence. */
function queueEvent(?ClientUser $member = null, string $verb = 'content.viewed'): ActivityEvent
{
    $member ??= ClientUser::firstOrCreate(
        ['client_id' => test()->client->id, 'external_user_id' => 'student-88213'],
        [
            'user_id' => User::factory()->clientProvisioned()->create()->id,
            'role' => 'learner',
            'status' => 'active',
        ],
    );

    $event = ActivityEvent::create([
        'id' => (string) Str::uuid7(),
        'occurred_at' => now(),
        'user_id' => $member->user_id,
        'client_id' => test()->client->id,
        'client_user_id' => $member->id,
        'verb' => $verb,
        'course_id' => test()->course->id,
        'publication_id' => test()->publication->id,
        'grant_source' => 'client',
        'payload' => ['seconds_spent' => 300],
    ]);

    $sequence = ClientOutboxState::where('client_id', test()->client->id)->value('next_sequence');
    ClientOutboxState::where('client_id', test()->client->id)->update(['next_sequence' => $sequence + 1]);

    ClientEventOutbox::create([
        'client_id' => test()->client->id,
        'sequence' => $sequence,
        'event_id' => $event->id,
        'event_occurred_at' => $event->occurred_at,
        'next_attempt_at' => now(),
    ]);

    return $event;
}

function deliverToClient(): int
{
    return app(DeliverClientEvents::class)->handle(test()->client->fresh());
}

/**
 * Http::fake() merges stubs and the *first* matching one wins, so calling it
 * again cannot make a failing endpoint start succeeding. Drive the response from
 * a holder the test can flip.
 */
function fakeEndpoint(object $state): void
{
    Http::fake(fn () => $state->ok
        ? Http::response(['ok' => true])
        : Http::response('boom', 500));
}

/*
|--------------------------------------------------------------------------
| Signed, ordered delivery
|--------------------------------------------------------------------------
*/

it('delivers pending events in sequence order with a signed body', function () {
    Http::fake(['sis.abcschool.edu/*' => Http::response(['ok' => true])]);

    queueEvent();
    queueEvent();
    queueEvent();

    expect(deliverToClient())->toBe(3);

    Http::assertSent(function ($request) {
        $body = $request->body();
        $decoded = json_decode($body, true);

        // Ordered, and the range is stated in the header for reconciliation.
        expect($decoded['sequence_range'])->toBe(['from' => 1, 'to' => 3])
            ->and($request->header('X-LMS-Sequence-Range')[0])->toBe('1-3');

        // t=<unix>,v1=<hmac over "{t}.{raw body}">
        [$t, $v1] = explode(',', $request->header('X-LMS-Signature')[0]);
        $timestamp = Str::after($t, 't=');
        $signature = Str::after($v1, 'v1=');

        return hash_equals(hash_hmac('sha256', "{$timestamp}.{$body}", 'whsec_abc'), $signature);
    });

    expect(ClientEventOutbox::whereNull('delivered_at')->count())->toBe(0);
});

/** The timestamp is inside the signed material, so a capture cannot be replayed. */
it('signs over the timestamp as well as the body', function () {
    Http::fake(['sis.abcschool.edu/*' => Http::response()]);
    queueEvent();
    deliverToClient();

    Http::assertSent(function ($request) {
        [$t, $v1] = explode(',', $request->header('X-LMS-Signature')[0]);
        $signature = Str::after($v1, 'v1=');

        // The same body under a different timestamp yields a different signature.
        $forged = hash_hmac('sha256', '999.'.$request->body(), 'whsec_abc');

        return ! hash_equals($forged, $signature);
    });
});

it('delivers nothing when the client has no webhook url', function () {
    Http::fake();
    $this->client->update(['report_webhook_url' => null]);
    queueEvent();

    expect(deliverToClient())->toBe(0);
    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| Failure, retry, park
|--------------------------------------------------------------------------
*/

it('retries a failed batch on a ladder without skipping it', function () {
    Http::fake(['sis.abcschool.edu/*' => Http::response('boom', 500)]);

    queueEvent();
    queueEvent();

    expect(deliverToClient())->toBe(0);

    $rows = ClientEventOutbox::orderBy('sequence')->get();

    expect($rows->pluck('attempts')->all())->toBe([1, 1])
        ->and($rows->first()->last_error)->not->toBeNull()
        ->and($rows->first()->delivered_at)->toBeNull()
        // Backed off, not skipped.
        ->and($rows->first()->next_attempt_at->timestamp)
        ->toBe(now()->addSeconds(DeliverClientEvents::RETRY_LADDER[0])->timestamp);

    // Not yet due: a second run does nothing rather than hammering.
    expect(deliverToClient())->toBe(0);
    expect(ClientEventOutbox::first()->attempts)->toBe(1);
});

it('parks the stream once the ladder is exhausted, and never steps over the batch', function () {
    $endpoint = (object) ['ok' => false];
    fakeEndpoint($endpoint);

    queueEvent();

    foreach (DeliverClientEvents::RETRY_LADDER as $seconds) {
        deliverToClient();
        $this->travel($seconds + 1)->seconds();
    }

    deliverToClient();   // the rung past the end

    $state = ClientOutboxState::firstOrFail();

    expect($state->isParked())->toBeTrue()
        ->and($state->parked_reason)->not->toBeNull();

    // A parked stream delivers nothing even once the endpoint recovers, and the
    // undelivered event stays exactly where it is until someone replays it.
    $endpoint->ok = true;

    expect(deliverToClient())->toBe(0)
        ->and(ClientEventOutbox::whereNull('delivered_at')->count())->toBe(1);
});

/**
 * A gap is indistinguishable from a lost event. Never skip.
 *
 * The backoff belongs to the stream, not the row: a freshly queued sequence 2
 * must not overtake a backed-off sequence 1.
 */
it('holds later events behind a failing one', function () {
    $endpoint = (object) ['ok' => false];
    fakeEndpoint($endpoint);

    queueEvent();
    deliverToClient();

    // A newer event arrives while sequence 1 is stuck. Its own next_attempt_at
    // is due immediately — and it must still wait.
    queueEvent();

    $endpoint->ok = true;

    expect(deliverToClient())->toBe(0)
        ->and(ClientEventOutbox::where('sequence', 2)->value('delivered_at'))->toBeNull();

    $this->travel(DeliverClientEvents::RETRY_LADDER[0] + 1)->seconds();

    expect(deliverToClient())->toBe(2);

    Http::assertSent(fn ($request) => json_decode($request->body(), true)['sequence_range']
        === ['from' => 1, 'to' => 2]);
});

it('resumes a parked stream on replay, from the requested sequence', function () {
    Http::fake(['sis.abcschool.edu/*' => Http::response(['ok' => true])]);

    queueEvent();
    queueEvent();
    queueEvent();
    deliverToClient();

    expect(ClientEventOutbox::whereNull('delivered_at')->count())->toBe(0);

    ClientOutboxState::where('client_id', $this->client->id)
        ->update(['parked_at' => now(), 'parked_reason' => 'endpoint down']);

    $replayed = app(ReplayOutbox::class)->handle($this->client, fromSequence: 2);

    expect($replayed)->toBe(2)
        ->and(ClientOutboxState::firstOrFail()->isParked())->toBeFalse();

    expect(deliverToClient())->toBe(2);

    Http::assertSent(fn ($request) => json_decode($request->body(), true)['sequence_range']
        === ['from' => 2, 'to' => 3]);
});

/*
|--------------------------------------------------------------------------
| Privacy: the boundary this whole subsystem exists to hold
|--------------------------------------------------------------------------
*/

/**
 * The single most valuable test in the reporting subsystem.
 *
 * One human, linked across contexts: they study for ABC School, and they also
 * hold a personal subscription. Their personal study is theirs. ABC's feed must
 * contain only what happened inside ABC's context.
 */
it('never leaks a linked account s personal activity into a client s feed', function () {
    Http::fake(['sis.abcschool.edu/*' => Http::response(['ok' => true])]);

    $member = ClientUser::create([
        'client_id' => $this->client->id,
        'external_user_id' => 'student-88213',
        'user_id' => User::factory()->clientProvisioned()->create()->id,
        'role' => 'learner',
        'status' => 'active',
    ]);

    // Two events, the same human, different contexts.
    $schoolEvent = queueEvent($member);

    $personalEvent = ActivityEvent::create([
        'id' => (string) Str::uuid7(),
        'occurred_at' => now(),
        'user_id' => $member->user_id,      // the same user_id
        'client_id' => null,                // …studying on their own time
        'client_user_id' => null,
        'verb' => 'content.viewed',
        'course_id' => $this->course->id,
        'publication_id' => $this->publication->id,
        'grant_source' => 'subscription',
    ]);

    deliverToClient();

    Http::assertSent(function ($request) use ($schoolEvent, $personalEvent) {
        $ids = array_column(json_decode($request->body(), true)['events'], 'id');

        return in_array($schoolEvent->id, $ids, true)
            && ! in_array($personalEvent->id, $ids, true);
    });

    // And the personal event was never queued for anybody.
    expect(ClientEventOutbox::where('event_id', $personalEvent->id)->exists())->toBeFalse();
});

it('never delivers one client s event to another', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);

    $xyz = Client::factory()->create([
        'slug' => 'xyz-school',
        'report_webhook_url' => 'https://sis.xyzschool.edu/hooks/lms',
        'webhook_secret' => 'whsec_xyz',
    ]);
    ClientOutboxState::create(['client_id' => $xyz->id, 'next_sequence' => 1]);

    $abcEvent = queueEvent();

    // An event for XYZ, queued on XYZ's own sequence.
    $xyzMember = ClientUser::create([
        'client_id' => $xyz->id, 'external_user_id' => 'x1',
        'user_id' => User::factory()->clientProvisioned()->create()->id,
        'role' => 'learner', 'status' => 'active',
    ]);
    $xyzEvent = ActivityEvent::create([
        'id' => (string) Str::uuid7(), 'occurred_at' => now(),
        'user_id' => $xyzMember->user_id, 'client_id' => $xyz->id, 'client_user_id' => $xyzMember->id,
        'verb' => 'content.viewed', 'course_id' => $this->course->id,
        'publication_id' => $this->publication->id, 'grant_source' => 'client',
    ]);
    ClientEventOutbox::create([
        'client_id' => $xyz->id, 'sequence' => 1, 'event_id' => $xyzEvent->id,
        'event_occurred_at' => $xyzEvent->occurred_at, 'next_attempt_at' => now(),
    ]);

    deliverToClient();
    app(DeliverClientEvents::class)->handle($xyz);

    Http::assertSent(function ($request) use ($abcEvent, $xyzEvent) {
        $ids = array_column(json_decode($request->body(), true)['events'], 'id');

        return str_contains($request->url(), 'abcschool')
            ? $ids === [$abcEvent->id]
            : $ids === [$xyzEvent->id];
    });
});

/*
|--------------------------------------------------------------------------
| Serialisation
|--------------------------------------------------------------------------
*/

/** The client re-identifies from their own system. We never echo a name back. */
it('reports the pseudonymous external user id, not a name or an email', function () {
    $member = ClientUser::create([
        'client_id' => $this->client->id,
        'external_user_id' => 'student-88213',
        'user_id' => User::factory()->clientProvisioned()->create()->id,
        'role' => 'learner',
        'external_name' => 'R. Sharma',
        'external_email' => 'r.sharma@abcschool.edu',
        'status' => 'active',
    ]);

    $payload = app(ActivitySerialiser::class)->forClient($this->client, queueEvent($member));
    $json = json_encode($payload, JSON_THROW_ON_ERROR);

    expect($payload['external_user_id'])->toBe('student-88213')
        ->and($json)->not->toContain('R. Sharma')
        ->and($json)->not->toContain('r.sharma@abcschool.edu');
});

it('emits xapi statements when the client asks for them', function () {
    $this->client->update(['settings' => ['report_format' => ActivitySerialiser::XAPI]]);

    $statement = app(ActivitySerialiser::class)->forClient(
        $this->client->fresh(),
        queueEvent(verb: Verb::ContentCompleted->value),
    );

    expect($statement['verb']['id'])->toBe('http://adlnet.gov/expapi/verbs/completed')
        ->and($statement['actor']['account']['name'])->toBe('student-88213')
        ->and($statement['result']['duration'])->toBe('PT300S');

    // account, never mbox: an email in a statement is PII shipped to an LRS.
    expect(json_encode($statement))->not->toContain('mbox');
});

/*
|--------------------------------------------------------------------------
| Pull API and delivery health
|--------------------------------------------------------------------------
*/

function asClientAdmin(Client $client): TestCase
{
    $user = User::factory()->clientProvisioned()->create();

    ClientUser::create([
        'client_id' => $client->id,
        'external_user_id' => 'admin-'.Str::random(4),
        'user_id' => $user->id,
        'role' => ClientUser::CLIENT_ADMIN,
        'status' => 'active',
    ]);

    $token = $user->createToken('console');
    $token->accessToken->forceFill(['client_id' => $client->id])->save();

    app('auth')->forgetGuards();

    return test()->withToken($token->plainTextToken);
}

/** Cursored on sequence, never on a timestamp: timestamps collide and skew. */
it('serves pull reporting cursored on sequence', function () {
    queueEvent();
    queueEvent();
    queueEvent();

    $response = asClientAdmin($this->client)
        ->getJson('/api/v1/client/activity?since_sequence=1&limit=1')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.sequence'))->toBe(2)
        ->and($response->json('meta.last_sequence'))->toBe(2)
        ->and($response->json('meta.has_more'))->toBeTrue();
});

it('never serves one client s events to another through the pull api', function () {
    queueEvent();

    $xyz = Client::factory()->create(['slug' => 'xyz-school']);
    ClientOutboxState::create(['client_id' => $xyz->id, 'next_sequence' => 1]);

    $response = asClientAdmin($xyz)->getJson('/api/v1/client/activity')->assertOk();

    expect($response->json('data'))->toBe([]);
});

it('reports delivery health, including a parked stream', function () {
    Http::fake(['sis.abcschool.edu/*' => Http::response('boom', 500)]);
    queueEvent();
    deliverToClient();

    $response = asClientAdmin($this->client)->getJson('/api/v1/client/delivery')->assertOk();

    expect($response->json('pending'))->toBe(1)
        ->and($response->json('oldest_pending_sequence'))->toBe(1)
        ->and($response->json('parked'))->toBeFalse()
        ->and($response->json('last_error'))->not->toBeNull();

    ClientOutboxState::where('client_id', $this->client->id)
        ->update(['parked_at' => now(), 'parked_reason' => 'endpoint gone']);

    expect(asClientAdmin($this->client)->getJson('/api/v1/client/delivery')->json('parked'))->toBeTrue();
});

it('refuses the pull api to a learner', function () {
    $user = User::factory()->clientProvisioned()->create();
    ClientUser::create([
        'client_id' => $this->client->id, 'external_user_id' => 'l1',
        'user_id' => $user->id, 'role' => 'learner', 'status' => 'active',
    ]);
    $token = $user->createToken('t');
    $token->accessToken->forceFill(['client_id' => $this->client->id])->save();

    app('auth')->forgetGuards();

    $this->withToken($token->plainTextToken)
        ->getJson('/api/v1/client/activity')
        ->assertForbidden();
});
