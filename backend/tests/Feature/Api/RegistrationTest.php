<?php

use App\Authorization\Roles;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('registers a new learner and returns a token', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Asha',
        'email' => 'asha@example.com',
        'password' => 'a-strong-password',
        'password_confirmation' => 'a-strong-password',
    ])->assertOk()->assertJsonStructure(['access_token', 'token_type', 'user' => ['id', 'name', 'email']]);

    $user = User::where('email', 'asha@example.com')->sole();
    expect($user->hasRole(Roles::LEARNER))->toBeTrue()
        ->and($user->status)->toBe(User::STATUS_ACTIVE)
        ->and($user->kind)->toBe(User::KIND_LOCAL);

    // The token actually authenticates.
    $this->withToken($response->json('access_token'))
        ->getJson('/api/v1/me/courses')
        ->assertOk();
});

it('rejects a duplicate email', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Dup',
        'email' => 'taken@example.com',
        'password' => 'a-strong-password',
        'password_confirmation' => 'a-strong-password',
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

it('rejects a weak or unconfirmed password', function () {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Weak',
        'email' => 'weak@example.com',
        'password' => 'short',
        'password_confirmation' => 'nope',
    ])->assertStatus(422)->assertJsonValidationErrors('password');
});

it('grants no entitlement on sign-up', function () {
    $token = $this->postJson('/api/v1/auth/register', [
        'name' => 'Fresh',
        'email' => 'fresh@example.com',
        'password' => 'a-strong-password',
        'password_confirmation' => 'a-strong-password',
    ])->json('access_token');

    // A brand-new learner sees no courses until they buy or are comped.
    $this->withToken($token)->getJson('/api/v1/me/courses')->assertOk()->assertJsonCount(0, 'data');
});
