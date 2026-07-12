<?php

use App\Authorization\Permissions;
use App\Authorization\Roles;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    RateLimiter::clear('login');
});

/*
|--------------------------------------------------------------------------
| Signing in
|--------------------------------------------------------------------------
*/

it('renders the login page to a guest', function () {
    $this->get('/studio/login')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/Login'));
});

it('sends a guest away from the studio', function () {
    $this->get('/studio')->assertRedirect('/studio/login');
});

it('signs a staff member in', function () {
    $author = staff();

    $this->post('/studio/login', ['email' => $author->email, 'password' => 'secret-password'])
        ->assertRedirect('/studio');

    $this->assertAuthenticatedAs($author);
    expect($author->fresh()->last_seen_at)->not->toBeNull();
});

/** A wrong password and an unknown email must be indistinguishable. */
it('does not reveal whether an email exists', function () {
    $author = staff();
    $message = 'Those credentials do not match our records.';

    $this->post('/studio/login', ['email' => $author->email, 'password' => 'nope'])
        ->assertSessionHasErrors(['email' => $message]);

    $this->flushSession();

    $this->post('/studio/login', ['email' => 'ghost@example.com', 'password' => 'nope'])
        ->assertSessionHasErrors(['email' => $message]);

    $this->assertGuest();
});

/*
|--------------------------------------------------------------------------
| Who may reach the studio
|--------------------------------------------------------------------------
*/

/**
 * A launched student holds no password and no email, so they cannot even reach
 * the login form. EnsureStaff is the second lock, and it must hold on its own.
 */
it('refuses a client-provisioned user even with a session', function () {
    $launched = User::factory()->clientProvisioned()->create();
    $launched->assignRole(Roles::LEARNER);

    $this->actingAs($launched)->get('/studio')->assertForbidden();
});

it('refuses a learner', function () {
    $this->actingAs(staff(Roles::LEARNER))->get('/studio')->assertForbidden();
});

it('refuses an instructor', function () {
    $this->actingAs(staff(Roles::INSTRUCTOR))->get('/studio')->assertForbidden();
});

it('refuses a suspended staff member', function () {
    $author = staff();
    $author->update(['status' => 'suspended']);

    $this->actingAs($author)->get('/studio')->assertForbidden();
});

it('admits every staff role', function () {
    foreach ([Roles::ADMIN, Roles::OPS, Roles::CONTENT_AUTHOR, Roles::CONTENT_REVIEWER] as $role) {
        $this->actingAs(staff($role))->get('/studio')->assertOk();
    }
});

/** The password path is closed at the model, not just at the middleware. */
it('refuses a client-provisioned user at the login form', function () {
    $launched = User::factory()->clientProvisioned()->create();

    expect($launched->email)->toBeNull()
        ->and($launched->password)->toBeNull();

    $this->post('/studio/login', ['email' => 'anything@example.com', 'password' => 'x'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

/*
|--------------------------------------------------------------------------
| Rate limiting
|--------------------------------------------------------------------------
*/

it('throttles login attempts', function () {
    foreach (range(1, 5) as $i) {
        $this->post('/studio/login', ['email' => 'a@b.com', 'password' => 'nope'])
            ->assertSessionHasErrors('email');
    }

    $this->post('/studio/login', ['email' => 'a@b.com', 'password' => 'nope'])
        ->assertStatus(429);
});

/*
|--------------------------------------------------------------------------
| What the shell is told
|--------------------------------------------------------------------------
*/

/**
 * The client renders what the server says it may render. It never re-derives
 * authorization — there is one authorization source.
 *
 * Permission names contain dots (`schema.view`), so Inertia's dot-path traversal
 * cannot address them individually. Read the whole map. The names stay as the
 * server spells them — one vocabulary, not two.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, bool>
 */
function abilities(TestResponse $response): array
{
    return $response->viewData('page')['props']['auth']['can'];
}

it('shares the user s abilities with every page', function () {
    $response = $this->actingAs(staff(Roles::CONTENT_AUTHOR))->get('/studio');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Dashboard')
        ->where('auth.user.roles', [Roles::CONTENT_AUTHOR])
    );

    $can = abilities($response);

    expect($can[Permissions::SCHEMA_VIEW])->toBeTrue()
        ->and($can[Permissions::COURSE_CREATE])->toBeTrue()
        // An author may not publish a course, and the nav must not offer it.
        ->and($can[Permissions::COURSE_PUBLISH])->toBeFalse();
});

it('tells a reviewer they may not create courses', function () {
    $can = abilities($this->actingAs(staff(Roles::CONTENT_REVIEWER))->get('/studio'));

    expect($can[Permissions::COURSE_CREATE])->toBeFalse()
        ->and($can[Permissions::SCHEMA_CREATE])->toBeFalse()
        ->and($can[Permissions::SCHEMA_VIEW])->toBeTrue();
});

/**
 * The sidebar shows a nav item only when the shared ability map holds its
 * permission. A link keyed to a permission the server never shares is invisible
 * to everyone — which is exactly how the Courses link disappeared once. Every
 * permission the nav keys off must be present in the map.
 */
it('shares every permission the nav keys off', function () {
    $can = abilities($this->actingAs(staff(Roles::CONTENT_AUTHOR))->get('/studio'));

    // Mirrors NAV in resources/js/studio/components/StudioLayout.tsx.
    foreach ([Permissions::SCHEMA_VIEW, Permissions::COURSE_VIEW_GRANTED, Permissions::QUESTION_MANAGE] as $permission) {
        expect($can)->toHaveKey($permission);
    }

    // An author holds a granted view, so the Courses link renders for them.
    expect($can[Permissions::COURSE_VIEW_GRANTED])->toBeTrue();
});

it('never leaks a password hash into the page props', function () {
    $response = $this->actingAs(staff())->get('/studio')->assertOk();

    expect($response->getContent())->not->toContain('$2y$');
});

it('signs out', function () {
    $this->actingAs(staff())->post('/studio/logout')->assertRedirect('/studio/login');

    $this->assertGuest();
});
