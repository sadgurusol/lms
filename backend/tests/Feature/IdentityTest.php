<?php

use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('allows many client-provisioned users to share a null email', function () {
    User::factory()->clientProvisioned()->count(3)->create();

    expect(User::whereNull('email')->count())->toBe(3);
});

it('refuses a password on a client-provisioned user', function () {
    // Straight to the database: the constraint must hold when a seeder, an
    // artisan command, or a 2am tinker session bypasses the model.
    expectDatabaseRejection(
        fn () => DB::table('users')->insert([
            'id' => Str::uuid7(),
            'name' => 'Launched Student',
            'kind' => User::KIND_CLIENT_PROVISIONED,
            'status' => 'active',
            'password' => 'any-hash',
            'created_at' => now(),
            'updated_at' => now(),
        ]),
        'users_provisioned_has_no_password',
    );
});

it('refuses an email on a client-provisioned user', function () {
    expectDatabaseRejection(
        fn () => DB::table('users')->insert([
            'id' => Str::uuid7(),
            'name' => 'Launched Student',
            'kind' => User::KIND_CLIENT_PROVISIONED,
            'status' => 'active',
            'email' => 'student@abcschool.edu',
            'created_at' => now(),
            'updated_at' => now(),
        ]),
        'users_provisioned_has_no_password',
    );
});

it('treats email uniqueness case-insensitively', function () {
    User::factory()->create(['email' => 'r.sharma@abcschool.edu']);

    expectDatabaseRejection(
        fn () => User::factory()->create(['email' => 'R.Sharma@ABCSchool.edu']),
        'users_email_unique',
    );
});

it('refuses a duplicate identity for the same provider', function () {
    $user = User::factory()->create();
    UserIdentity::create([
        'user_id' => $user->id,
        'provider' => UserIdentity::clientProvider('abc-school'),
        'provider_uid' => 'student-88213',
    ]);

    expectDatabaseRejection(
        fn () => UserIdentity::create([
            'user_id' => User::factory()->create()->id,
            'provider' => UserIdentity::clientProvider('abc-school'),
            'provider_uid' => 'student-88213',
        ]),
        'user_identities_provider_provider_uid_unique',
    );
});

it('lets the same external id exist under two different clients', function () {
    $abc = User::factory()->clientProvisioned()->create();
    $xyz = User::factory()->clientProvisioned()->create();

    UserIdentity::create([
        'user_id' => $abc->id,
        'provider' => UserIdentity::clientProvider('abc-school'),
        'provider_uid' => 'student-1',
    ]);
    UserIdentity::create([
        'user_id' => $xyz->id,
        'provider' => UserIdentity::clientProvider('xyz-school'),
        'provider_uid' => 'student-1',
    ]);

    expect(UserIdentity::where('provider_uid', 'student-1')->count())->toBe(2);
});

/**
 * The account-takeover guard, stated as a test.
 *
 * A launch from ABC School asserting an email that belongs to an existing B2C
 * account must not reach that account. The schema enforces it: a launch can
 * only ever mint a `client:{slug}` identity, and the B2C user is reachable only
 * through their `password` identity.
 */
it('keeps a client identity separate from a password identity with the same email', function () {
    $b2c = User::factory()->create(['email' => 'r.sharma@abcschool.edu']);
    UserIdentity::create([
        'user_id' => $b2c->id,
        'provider' => UserIdentity::PROVIDER_PASSWORD,
        'provider_uid' => 'r.sharma@abcschool.edu',
        'verified_at' => now(),
    ]);

    // ABC School launches a user claiming the same email address.
    $launched = User::factory()->clientProvisioned()->create();
    UserIdentity::create([
        'user_id' => $launched->id,
        'provider' => UserIdentity::clientProvider('abc-school'),
        'provider_uid' => 'attacker-1',
    ]);

    expect($launched->id)->not->toBe($b2c->id)
        ->and($launched->email)->toBeNull()
        ->and($launched->identities()->where('provider', UserIdentity::PROVIDER_PASSWORD)->exists())
        ->toBeFalse();
});
