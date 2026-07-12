<?php

use App\Launch\CustomJwtValidator;
use App\Launch\InvalidLaunch;
use App\Launch\LaunchRequest;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Launch\ProvisionClientUser;
use Firebase\JWT\JWT;
use Illuminate\Support\Str;
use Tests\Support\LaunchKeys;

const AUDIENCE = 'https://api.example.com/api/v1/launch';

beforeEach(function () {
    config()->set('launch.audience', AUDIENCE);

    $this->client = Client::factory()->create(['slug' => 'abc-school']);
    $this->keys = new LaunchKeys;
    $this->keys->register($this->client);
});

/** A well-formed launch token. */
function claims(array $overrides = []): array
{
    return [
        'iss' => 'abc-school',
        'aud' => AUDIENCE,
        'sub' => 'student-88213',
        'jti' => (string) Str::uuid7(),
        'iat' => now()->timestamp,
        'exp' => now()->addSeconds(90)->timestamp,
        'nonce' => Str::random(16),
        'name' => 'R. Sharma',
        'role' => 'learner',
        ...$overrides,
    ];
}

function validateToken(string $token): LaunchRequest
{
    return app(CustomJwtValidator::class)->validate($token);
}

function expectRefusal(string $token, string $reason): void
{
    try {
        validateToken($token);
    } catch (InvalidLaunch $e) {
        expect($e->reason)->toBe($reason);

        return;
    }

    throw new RuntimeException("Expected the launch to be refused with [{$reason}], but it was accepted.");
}

/*
|--------------------------------------------------------------------------
| The happy path
|--------------------------------------------------------------------------
*/

it('accepts a correctly signed launch', function () {
    $launch = validateToken($this->keys->sign(claims()));

    expect($launch->clientId)->toBe($this->client->id)
        ->and($launch->externalUserId)->toBe('student-88213')
        ->and($launch->role)->toBe('learner');
});

/*
|--------------------------------------------------------------------------
| Signature and algorithm
|--------------------------------------------------------------------------
*/

it('refuses a token signed by the wrong key', function () {
    $attacker = new LaunchKeys('kid-1');

    expectRefusal($attacker->sign(claims()), 'bad_signature');
});

/** `alg: none` is the oldest JWT vulnerability there is. */
it('refuses an unsigned token', function () {
    $header = JWT::urlsafeB64Encode(json_encode(['typ' => 'JWT', 'alg' => 'none', 'kid' => 'kid-1']));
    $payload = JWT::urlsafeB64Encode(json_encode(claims()));

    expectRefusal("{$header}.{$payload}.", 'bad_signature');
});

/**
 * Algorithm confusion: sign with HS256 using our own *public* key as the HMAC
 * secret. If the verifier read `alg` from the token header it would happily
 * accept. It reads the algorithm from our record of the key instead.
 */
it('refuses an hs256 token forged with the public key as the hmac secret', function () {
    $forged = JWT::encode(claims(), $this->keys->publicKey, 'HS256', 'kid-1');

    expectRefusal($forged, 'bad_signature');
});

it('refuses a token whose kid we do not know', function () {
    $other = new LaunchKeys('kid-unknown');

    expectRefusal($other->sign(claims()), 'bad_signature');
});

it('refuses a launch when the client has revoked its key', function () {
    $this->client->keys()->update(['status' => 'revoked']);

    expectRefusal($this->keys->sign(claims()), 'no_usable_key');
});

/** A client mid-rotation signs with the new key while old tokens are in flight. */
it('accepts a token signed by a rotating key', function () {
    $this->client->keys()->update(['status' => 'rotating']);

    expect(validateToken($this->keys->sign(claims()))->externalUserId)->toBe('student-88213');
});

/*
|--------------------------------------------------------------------------
| Claims
|--------------------------------------------------------------------------
*/

it('refuses a token addressed to another service', function () {
    expectRefusal($this->keys->sign(claims(['aud' => 'https://someone-else.test'])), 'bad_audience');
});

it('refuses an expired token', function () {
    expectRefusal($this->keys->sign(claims([
        'iat' => now()->subMinutes(10)->timestamp,
        'exp' => now()->subMinutes(9)->timestamp,
    ])), 'bad_signature');
});

/** A token valid for a day is a bearer credential a client can hand to anyone. */
it('refuses a token with a long lifetime', function () {
    expectRefusal($this->keys->sign(claims([
        'iat' => now()->timestamp,
        'exp' => now()->addDay()->timestamp,
    ])), 'lifetime_too_long');
});

it('tolerates a minute of clock skew', function () {
    $launch = validateToken($this->keys->sign(claims([
        'iat' => now()->addSeconds(30)->timestamp,
        'exp' => now()->addSeconds(120)->timestamp,
    ])));

    expect($launch->externalUserId)->toBe('student-88213');
});

it('refuses a token with no jti or no nonce', function () {
    $noJti = claims();
    unset($noJti['jti']);
    expectRefusal($this->keys->sign($noJti), 'missing_jti');

    $noNonce = claims();
    unset($noNonce['nonce']);
    expectRefusal($this->keys->sign($noNonce), 'missing_nonce');
});

/*
|--------------------------------------------------------------------------
| Replay
|--------------------------------------------------------------------------
*/

it('refuses a replayed token', function () {
    $token = $this->keys->sign(claims());

    expect(validateToken($token)->externalUserId)->toBe('student-88213');

    expectRefusal($token, 'replayed');
});

it('refuses a fresh token that reuses a spent nonce', function () {
    $nonce = Str::random(16);

    validateToken($this->keys->sign(claims(['nonce' => $nonce])));

    expectRefusal($this->keys->sign(claims(['nonce' => $nonce])), 'replayed');
});

/** One client's spent jti must not block another client's. */
it('scopes the replay guard per client', function () {
    $other = Client::factory()->create(['slug' => 'xyz-school']);
    $otherKeys = new LaunchKeys;
    $otherKeys->register($other);

    $jti = (string) Str::uuid7();

    validateToken($this->keys->sign(claims(['jti' => $jti])));

    $launch = validateToken($otherKeys->sign(claims(['iss' => 'xyz-school', 'jti' => $jti])));

    expect($launch->clientId)->toBe($other->id);
});

/*
|--------------------------------------------------------------------------
| The client itself
|--------------------------------------------------------------------------
*/

it('refuses a launch from an unknown issuer', function () {
    expectRefusal($this->keys->sign(claims(['iss' => 'not-a-client'])), 'unknown_client');
});

it('refuses a launch from a suspended client', function () {
    $this->client->update(['status' => 'suspended']);

    expectRefusal($this->keys->sign(claims()), 'unknown_client');
});

it('refuses a custom-jwt launch against a client configured for lti', function () {
    $this->client->update(['integration' => Client::LTI]);

    expectRefusal($this->keys->sign(claims()), 'wrong_integration');
});

/*
|--------------------------------------------------------------------------
| Role mapping
|--------------------------------------------------------------------------
*/

/**
 * A client that starts asserting a role string we did not anticipate must not
 * thereby mint administrators.
 */
it('maps an unrecognised role down to learner, never up', function () {
    foreach (['admin', 'superuser', 'ADMIN', '', null, 'content_author'] as $role) {
        $launch = validateToken($this->keys->sign(claims(['role' => $role])));

        expect($launch->role)->toBe('learner', 'role ['.var_export($role, true).'] must not escalate');
    }

    expect(validateToken($this->keys->sign(claims(['role' => 'instructor'])))->role)->toBe('instructor')
        ->and(validateToken($this->keys->sign(claims(['role' => 'client_admin'])))->role)->toBe('client_admin');
});

/*
|--------------------------------------------------------------------------
| The account-takeover guard
|--------------------------------------------------------------------------
*/

/**
 * A launch may only ever create or reuse a `client:{slug}` identity. An email in
 * a launch token is a claim by the client *about a third party*: enough to
 * display, never enough to authenticate.
 */
it('never links a launch to an existing b2c account by email', function () {
    $victim = User::factory()->create(['email' => 'r.sharma@abcschool.edu']);
    UserIdentity::create([
        'user_id' => $victim->id,
        'provider' => UserIdentity::PROVIDER_PASSWORD,
        'provider_uid' => 'r.sharma@abcschool.edu',
        'verified_at' => now(),
    ]);

    // The SIS is compromised, and signs a launch claiming the victim's email.
    $launch = validateToken($this->keys->sign(claims([
        'sub' => 'attacker-1',
        'email' => 'r.sharma@abcschool.edu',
    ])));

    $clientUser = app(ProvisionClientUser::class)->handle($this->client, $launch);

    expect($clientUser->user_id)->not->toBe($victim->id)
        ->and($clientUser->user->email)->toBeNull()
        ->and($clientUser->user->password)->toBeNull()
        ->and($clientUser->user->isClientProvisioned())->toBeTrue()
        // The email is stored for display, and nowhere near authentication.
        ->and($clientUser->external_email)->toBe('r.sharma@abcschool.edu');

    // The victim's account is untouched and still reachable only by password.
    expect($victim->fresh()->email)->toBe('r.sharma@abcschool.edu')
        ->and(ClientUser::where('user_id', $victim->id)->exists())->toBeFalse();
});

it('reuses the same lms user across launches, and keeps clients separate', function () {
    $first = app(ProvisionClientUser::class)
        ->handle($this->client, validateToken($this->keys->sign(claims())));

    $second = app(ProvisionClientUser::class)
        ->handle($this->client, validateToken($this->keys->sign(claims())));

    expect($second->id)->toBe($first->id)
        ->and(User::where('kind', User::KIND_CLIENT_PROVISIONED)->count())->toBe(1);

    // The same external id under another client is a different person.
    $other = Client::factory()->create(['slug' => 'xyz-school']);
    $otherKeys = new LaunchKeys;
    $otherKeys->register($other);

    $third = app(ProvisionClientUser::class)
        ->handle($other, validateToken($otherKeys->sign(claims(['iss' => 'xyz-school']))));

    expect($third->user_id)->not->toBe($first->user_id)
        ->and(User::where('kind', User::KIND_CLIENT_PROVISIONED)->count())->toBe(2);
});

it('does not let a launch reactivate a deactivated roster member', function () {
    $clientUser = app(ProvisionClientUser::class)
        ->handle($this->client, validateToken($this->keys->sign(claims())));

    $clientUser->update(['status' => 'deactivated']);

    app(ProvisionClientUser::class)
        ->handle($this->client, validateToken($this->keys->sign(claims())));

    expect($clientUser->fresh()->status)->toBe('deactivated');
});
