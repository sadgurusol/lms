<?php

use App\Authorization\Roles;
use App\Models\CompGrant;
use App\Models\Plan;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\ManageProduct;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->course] = publishedTextbookCourse();
    $this->product = Product::factory()->create(['name' => 'Algebra Course', 'kind' => Product::KIND_COURSE]);
    app(ManageProduct::class)->addCourse($this->product, $this->course);
    $this->plan = Plan::factory()->create(['product_id' => $this->product->id, 'name' => 'Monthly', 'price_minor' => 49900]);

    $this->learner = User::factory()->withRole(Roles::LEARNER)->create();
});

it('requires authentication', function () {
    $this->getJson('/api/v1/me/catalog')->assertUnauthorized();
});

it('lists a buyable product with its courses and plans', function () {
    $this->actingAs($this->learner, 'sanctum')
        ->getJson('/api/v1/me/catalog')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Algebra Course')
        ->assertJsonPath('data.0.owned', false)
        ->assertJsonPath('data.0.courses.0.id', $this->course->id)
        ->assertJsonPath('data.0.plans.0.code', $this->plan->code)
        ->assertJsonPath('data.0.plans.0.price_minor', 49900)
        ->assertJsonPath('data.0.plans.0.is_subscription', true);
});

it('marks a product the learner already owns', function () {
    CompGrant::create([
        'user_id' => $this->learner->id,
        'product_id' => $this->product->id,
        'reason' => CompGrant::REASON_TRIAL,
        'starts_at' => now()->subMinute(),
    ]);

    $this->actingAs($this->learner, 'sanctum')
        ->getJson('/api/v1/me/catalog')
        ->assertOk()
        ->assertJsonPath('data.0.owned', true);
});

it('hides a product that has no active plan to sell', function () {
    $this->plan->update(['status' => 'retired']);

    $this->actingAs($this->learner, 'sanctum')
        ->getJson('/api/v1/me/catalog')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('marks a one-time plan as not a subscription', function () {
    Plan::factory()->create([
        'product_id' => $this->product->id,
        'interval' => Plan::ONE_TIME,
        'name' => 'Lifetime',
        'price_minor' => 199900,
    ]);

    $data = $this->actingAs($this->learner, 'sanctum')->getJson('/api/v1/me/catalog')->json('data.0.plans');

    $lifetime = collect($data)->firstWhere('name', 'Lifetime');
    expect($lifetime['is_subscription'])->toBeFalse();
});
