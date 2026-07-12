<?php

use App\Authorization\Roles;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    RateLimiter::clear('login');
});

it('issues a token for valid B2C credentials', function () {
    $user = User::factory()->withRole(Roles::LEARNER)->create(['password' => 'secret-password']);

    $response = $this->postJson('/api/v1/auth/token', [
        'email' => $user->email,
        'password' => 'secret-password',
    ])->assertOk();

    expect($response->json('access_token'))->toBeString()->not->toBeEmpty()
        ->and($response->json('token_type'))->toBe('Bearer')
        ->and($response->json('user.id'))->toBe($user->id);

    // The token actually authenticates the API.
    $this->withToken($response->json('access_token'))
        ->getJson('/api/v1/me/courses')
        ->assertOk();
});

it('does not reveal whether an email exists', function () {
    $user = User::factory()->withRole(Roles::LEARNER)->create(['password' => 'secret-password']);
    $message = 'Those credentials do not match our records.';

    $this->postJson('/api/v1/auth/token', ['email' => $user->email, 'password' => 'wrong'])
        ->assertStatus(422)
        ->assertJsonPath('errors.email.0', $message);

    $this->postJson('/api/v1/auth/token', ['email' => 'ghost@example.com', 'password' => 'wrong'])
        ->assertStatus(422)
        ->assertJsonPath('errors.email.0', $message);
});

it('refuses a client-provisioned account (no password)', function () {
    $launched = User::factory()->clientProvisioned()->create();

    // It has no email either, but even given one, there is no password to match.
    $this->postJson('/api/v1/auth/token', ['email' => 'anything@example.com', 'password' => 'x'])
        ->assertStatus(422);
});

it('refuses a suspended account', function () {
    $user = User::factory()->withRole(Roles::LEARNER)->create([
        'password' => 'secret-password',
        'status' => 'suspended',
    ]);

    $this->postJson('/api/v1/auth/token', ['email' => $user->email, 'password' => 'secret-password'])
        ->assertStatus(422);
});

it('throttles repeated attempts', function () {
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/auth/token', ['email' => 'a@b.com', 'password' => 'nope'])
            ->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/token', ['email' => 'a@b.com', 'password' => 'nope'])
        ->assertStatus(429);
});

it('revokes the token on logout', function () {
    $user = User::factory()->withRole(Roles::LEARNER)->create(['password' => 'secret-password']);
    $token = $this->postJson('/api/v1/auth/token', [
        'email' => $user->email, 'password' => 'secret-password',
    ])->json('access_token');

    expect(PersonalAccessToken::count())->toBe(1);

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

    // The token row is gone, so a fresh request (new container in production)
    // can no longer resolve it. Asserting on re-auth in-test is unreliable: one
    // test reuses the app container and Sanctum caches the resolved user.
    expect(PersonalAccessToken::count())->toBe(0);
});
