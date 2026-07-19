<?php

use App\Authorization\Roles;
use App\Models\CompGrant;
use App\Models\Course;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\ManageProduct;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = staff(Roles::ADMIN);
});

/** A B2C learner (direct sign-up). */
function directLearner(string $email = 'learner@example.com', string $name = 'Learner'): User
{
    $user = User::factory()->create(['email' => $email, 'name' => $name, 'kind' => User::KIND_LOCAL]);
    $user->assignRole(Roles::LEARNER);

    return $user;
}

it('lists B2C learners to an admin and refuses others', function () {
    directLearner();

    $this->actingAs($this->admin)
        ->get('/studio/learners')
        ->assertInertia(fn (AssertableInertia $page) => $page->component('learners/Index')->has('learners', 1));

    $this->actingAs(staff(Roles::CONTENT_AUTHOR))->get('/studio/learners')->assertForbidden();
});

it('searches learners by name or email', function () {
    directLearner('priya@example.com', 'Priya');
    directLearner('sam@example.com', 'Sam');

    $this->actingAs($this->admin)
        ->get('/studio/learners?search=priya')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('learners', 1)->where('learners.0.email', 'priya@example.com'));
});

it('excludes client-provisioned (B2B) learners', function () {
    $b2b = User::factory()->clientProvisioned()->create();
    $b2b->assignRole(Roles::LEARNER);
    directLearner();

    $this->actingAs($this->admin)
        ->get('/studio/learners')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('learners', 1));
});

it('suspends and reactivates a learner', function () {
    $learner = directLearner();

    $this->actingAs($this->admin)
        ->from('/studio/learners')
        ->patch("/studio/learners/{$learner->id}/status", ['status' => 'suspended'])
        ->assertSessionHas('success');
    expect($learner->fresh()->status)->toBe(User::STATUS_SUSPENDED);

    $this->actingAs($this->admin)
        ->patch("/studio/learners/{$learner->id}/status", ['status' => 'active'])
        ->assertSessionHas('success');
    expect($learner->fresh()->status)->toBe(User::STATUS_ACTIVE);
});

it('comps a learner into a product and shows the access', function () {
    [$course] = publishedTextbookCourse();
    $product = Product::factory()->create();
    app(ManageProduct::class)->addCourse($product, $course);
    $learner = directLearner();

    $this->actingAs($this->admin)
        ->from("/studio/learners/{$learner->id}")
        ->post("/studio/learners/{$learner->id}/comps", ['product_id' => $product->id])
        ->assertSessionHas('success');

    expect(CompGrant::where('user_id', $learner->id)->where('product_id', $product->id)->exists())->toBeTrue();

    // The detail page now lists the course they can access.
    $this->actingAs($this->admin)
        ->get("/studio/learners/{$learner->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('learners/Show')
            ->has('courses', 1)
            ->where('courses.0.id', $course->id)
            ->has('comps', 1),
        );
});

it('revokes a comp grant', function () {
    $product = Product::factory()->create();
    $learner = directLearner();
    $grant = CompGrant::create([
        'user_id' => $learner->id,
        'product_id' => $product->id,
        'reason' => CompGrant::REASON_SUPPORT,
        'starts_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->from("/studio/learners/{$learner->id}")
        ->delete("/studio/comps/{$grant->id}")
        ->assertSessionHas('success');

    expect(CompGrant::find($grant->id))->toBeNull();
});
