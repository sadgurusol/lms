<?php

use App\Authorization\Permissions;
use App\Authorization\Roles;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('seeds every role in the catalogue', function () {
    foreach (Roles::all() as $role) {
        expect(Role::where('name', $role)->exists())
            ->toBeTrue("role [{$role}] was not seeded");
    }
});

it('seeds every permission in the catalogue', function () {
    $seeded = Permission::pluck('name')->all();

    expect($seeded)->toEqualCanonicalizing(Permissions::all());
});

it('lets an admin bypass any gate, including abilities that do not exist', function () {
    $admin = User::factory()->withRole(Roles::ADMIN)->create();

    expect(Gate::forUser($admin)->allows(Permissions::COURSE_PUBLISH))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('an.ability.nobody.defined'))->toBeTrue();
});

/**
 * Gate::before must return null for non-admins, not false.
 *
 * Returning false short-circuits the gate as an outright denial, silently
 * revoking every permission every non-admin holds. The bug is invisible until
 * someone notices authors cannot save. This test is the tripwire.
 */
it('does not revoke non-admin permissions through the admin bypass', function () {
    $learner = User::factory()->withRole(Roles::LEARNER)->create();

    expect(Gate::forUser($learner)->allows(Permissions::ATTEMPT_TAKE))->toBeTrue()
        ->and(Gate::forUser($learner)->allows(Permissions::COURSE_PUBLISH))->toBeFalse();
});

it('grants course.publish to nobody but admin', function () {
    foreach (Roles::all() as $role) {
        $user = User::factory()->withRole($role)->create();

        expect(Gate::forUser($user)->allows(Permissions::COURSE_PUBLISH))
            ->toBe($role === Roles::ADMIN, "role [{$role}] has the wrong publish authority");
    }
});

it('denies attempt.take to every staff role', function () {
    $staff = [Roles::OPS, Roles::CONTENT_AUTHOR, Roles::CONTENT_REVIEWER];

    foreach ($staff as $role) {
        $user = User::factory()->withRole($role)->create();

        expect(Gate::forUser($user)->allows(Permissions::ATTEMPT_TAKE))->toBeFalse(
            "staff role [{$role}] should not be able to take assessments"
        );
    }
});

it('keeps ops away from content and authors away from clients', function () {
    $ops = User::factory()->withRole(Roles::OPS)->create();
    $author = User::factory()->withRole(Roles::CONTENT_AUTHOR)->create();

    // The person rotating a client's signing key at 11pm must not also be able
    // to edit Grade 10 English.
    expect(Gate::forUser($ops)->allows(Permissions::COURSE_UPDATE))->toBeFalse()
        ->and(Gate::forUser($ops)->allows(Permissions::CLIENT_KEY_ROTATE))->toBeTrue()
        ->and(Gate::forUser($author)->allows(Permissions::COURSE_UPDATE))->toBeTrue()
        ->and(Gate::forUser($author)->allows(Permissions::CLIENT_KEY_ROTATE))->toBeFalse();
});

it('gives a reviewer no authority to edit content', function () {
    $reviewer = User::factory()->withRole(Roles::CONTENT_REVIEWER)->create();

    expect(Gate::forUser($reviewer)->allows(Permissions::COURSE_REVIEW))->toBeTrue()
        ->and(Gate::forUser($reviewer)->allows(Permissions::NODE_UPDATE))->toBeFalse()
        ->and(Gate::forUser($reviewer)->allows(Permissions::BLOCK_UPDATE))->toBeFalse();
});
