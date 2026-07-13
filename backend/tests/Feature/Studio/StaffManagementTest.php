<?php

use App\Authorization\Roles;
use App\Models\User;
use App\Notifications\StaffInvitation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = staff(Roles::ADMIN);
});

it('lists staff to an admin and refuses everyone else', function () {
    $this->actingAs($this->admin)
        ->get('/studio/users')
        ->assertInertia(fn (AssertableInertia $page) => $page->component('users/Index')->has('staff'));

    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->get('/studio/users')
        ->assertForbidden();
});

it('invites a staff member as an invited, password-less account', function () {
    Notification::fake();

    $this->actingAs($this->admin)
        ->from('/studio/users')
        ->post('/studio/users', ['name' => 'Priya', 'email' => 'priya@example.com', 'role' => 'content_author'])
        ->assertSessionHas('success');

    $user = User::where('email', 'priya@example.com')->sole();
    expect($user->status)->toBe(User::STATUS_INVITED)
        ->and($user->hasRole(Roles::CONTENT_AUTHOR))->toBeTrue();

    Notification::assertSentTo($user, StaffInvitation::class);
});

it('refuses to invite a duplicate email', function () {
    $this->actingAs($this->admin)
        ->from('/studio/users')
        ->post('/studio/users', ['name' => 'Dup', 'email' => $this->admin->email, 'role' => 'ops'])
        ->assertSessionHasErrors('email');
});

it('lets an invitee set a password, which activates and signs them in', function () {
    $invitee = User::factory()->create(['email' => 'new@example.com', 'status' => User::STATUS_INVITED]);
    $invitee->assignRole(Roles::CONTENT_REVIEWER);
    $token = Password::broker()->createToken($invitee);

    // The link renders the set-password page.
    $this->get("/studio/set-password/{$token}?email=new@example.com")
        ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/SetPassword')->where('email', 'new@example.com'));

    $this->post('/studio/set-password', [
        'token' => $token,
        'email' => 'new@example.com',
        'password' => 'a-strong-password-1',
        'password_confirmation' => 'a-strong-password-1',
    ])->assertRedirect('/studio');

    $invitee->refresh();
    expect($invitee->status)->toBe(User::STATUS_ACTIVE);
    $this->assertAuthenticatedAs($invitee);
});

it('rejects an invalid or expired token', function () {
    User::factory()->create(['email' => 'x@example.com', 'status' => User::STATUS_INVITED]);

    $this->from('/studio/set-password/bad-token')
        ->post('/studio/set-password', [
            'token' => 'bad-token',
            'email' => 'x@example.com',
            'password' => 'a-strong-password-1',
            'password_confirmation' => 'a-strong-password-1',
        ])
        ->assertSessionHasErrors('email');
});

it('changes a role and suspends a member', function () {
    $member = staff(Roles::CONTENT_AUTHOR);

    $this->actingAs($this->admin)
        ->from('/studio/users')
        ->patch("/studio/users/{$member->id}", ['role' => 'content_reviewer'])
        ->assertSessionHas('success');
    expect($member->fresh()->hasRole(Roles::CONTENT_REVIEWER))->toBeTrue();

    $this->actingAs($this->admin)
        ->patch("/studio/users/{$member->id}", ['status' => 'suspended'])
        ->assertSessionHas('success');
    expect($member->fresh()->status)->toBe(User::STATUS_SUSPENDED);
});

it('will not change the last active administrator', function () {
    // $this->admin is the only admin.
    $this->actingAs($this->admin)
        ->from('/studio/users')
        ->patch("/studio/users/{$this->admin->id}", ['role' => 'ops'])
        ->assertSessionHas('error');

    expect($this->admin->fresh()->hasRole(Roles::ADMIN))->toBeTrue();
});

it('resends an invitation but not to someone already active', function () {
    Notification::fake();
    $invited = User::factory()->create(['status' => User::STATUS_INVITED]);
    $invited->assignRole(Roles::INSTRUCTOR);

    $this->actingAs($this->admin)
        ->from('/studio/users')
        ->post("/studio/users/{$invited->id}/invite")
        ->assertSessionHas('success');
    Notification::assertSentTo($invited, StaffInvitation::class);

    $active = staff(Roles::OPS); // active by default
    $this->actingAs($this->admin)
        ->post("/studio/users/{$active->id}/invite")
        ->assertStatus(422);
});
