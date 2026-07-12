<?php

use App\Authorization\Roles;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Product;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Access
|--------------------------------------------------------------------------
*/

it('lists products for ops', function () {
    Product::factory()->create(['name' => 'Grade 10 English', 'sku' => 'ENG-10']);

    $this->actingAs(staff(Roles::OPS))
        ->get('/ops/products')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('products/Index')
            ->has('products', 1)
            ->where('products.0.sku', 'ENG-10')
            ->where('can.create', true)
        );
});

/** Authors hold product.view (to see where their courses are sold) but not manage. */
it('lets an author view the catalogue read-only but not create', function () {
    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->get('/ops/products')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.create', false));
});

it('refuses the catalogue to a role without product.view', function () {
    $this->actingAs(staff(Roles::INSTRUCTOR))->get('/ops/products')->assertForbidden();
});

it('sends a guest to login', function () {
    $this->get('/ops/products')->assertRedirect('/studio/login');
});

/*
|--------------------------------------------------------------------------
| Creating and editing
|--------------------------------------------------------------------------
*/

it('creates a product', function () {
    $this->actingAs(staff(Roles::OPS))
        ->post('/ops/products', ['sku' => 'ENG-10', 'name' => 'Grade 10 English', 'kind' => 'course', 'status' => 'draft'])
        ->assertRedirect();

    $product = Product::sole();
    expect($product->sku)->toBe('ENG-10')
        ->and($product->kind)->toBe('course')
        ->and($product->status)->toBe('draft');
});

it('rejects a duplicate sku', function () {
    Product::factory()->create(['sku' => 'ENG-10']);

    $this->actingAs(staff(Roles::OPS))
        ->from('/ops/products')
        ->post('/ops/products', ['sku' => 'ENG-10', 'name' => 'Dup', 'kind' => 'course', 'status' => 'draft'])
        ->assertSessionHasErrors('sku');

    expect(Product::count())->toBe(1);
});

it('rejects an invalid kind', function () {
    $this->actingAs(staff(Roles::OPS))
        ->from('/ops/products')
        ->post('/ops/products', ['sku' => 'X', 'name' => 'X', 'kind' => 'nonsense', 'status' => 'draft'])
        ->assertSessionHasErrors('kind');
});

it('updates a product', function () {
    $product = Product::factory()->create(['status' => 'draft']);

    $this->actingAs(staff(Roles::OPS))
        ->from("/ops/products/{$product->id}")
        ->patch("/ops/products/{$product->id}", [
            'sku' => $product->sku, 'name' => 'Renamed', 'kind' => 'bundle', 'status' => 'active',
        ])
        ->assertSessionHas('success');

    $product->refresh();
    expect($product->name)->toBe('Renamed')
        ->and($product->kind)->toBe('bundle')
        ->and($product->status)->toBe('active');
});

/*
|--------------------------------------------------------------------------
| Course membership — through ManageProduct (audited, cache-busting)
|--------------------------------------------------------------------------
*/

it('adds a course to a product', function () {
    $product = Product::factory()->create();
    [$course] = textbookCourse();

    $this->actingAs(staff(Roles::OPS))
        ->from("/ops/products/{$product->id}")
        ->post("/ops/products/{$product->id}/courses", ['course_id' => $course->id])
        ->assertSessionHas('success');

    expect($product->courses()->whereKey($course->id)->exists())->toBeTrue();
});

it('does not duplicate a course already in the product', function () {
    $product = Product::factory()->create();
    [$course] = textbookCourse();
    $product->courses()->attach($course->id, ['added_at' => now()]);

    $this->actingAs(staff(Roles::OPS))
        ->from("/ops/products/{$product->id}")
        ->post("/ops/products/{$product->id}/courses", ['course_id' => $course->id])
        ->assertSessionHas('success');

    expect($product->courses()->count())->toBe(1);
});

it('removes a course from a product', function () {
    $product = Product::factory()->create();
    [$course] = textbookCourse();
    $product->courses()->attach($course->id, ['added_at' => now()]);

    $this->actingAs(staff(Roles::OPS))
        ->from("/ops/products/{$product->id}")
        ->delete("/ops/products/{$product->id}/courses/{$course->id}")
        ->assertSessionHas('success');

    expect($product->courses()->count())->toBe(0);
});

it('records a course-membership change in the audit log', function () {
    $product = Product::factory()->create();
    [$course] = textbookCourse();

    $this->actingAs(staff(Roles::OPS))
        ->post("/ops/products/{$product->id}/courses", ['course_id' => $course->id]);

    // ManageProduct audits this write; the entitlement blast radius is everyone.
    expect(AuditLog::where('action', 'product.course_added')->exists())->toBeTrue();
});

it('shows the product editor with members and available courses', function () {
    $product = Product::factory()->create();
    [$inCourse] = textbookCourse();
    $product->courses()->attach($inCourse->id, ['added_at' => now()]);
    [$outCourse] = textbookCourse();

    $this->actingAs(staff(Roles::OPS))
        ->get("/ops/products/{$product->id}")
        ->assertInertia(function (AssertableInertia $page) use ($inCourse, $outCourse) {
            $props = $page->component('products/Show')->where('can.manage', true)->toArray()['props'];

            expect(collect($props['courses'])->pluck('id')->all())->toBe([$inCourse->id]);
            // The other course is offered as available, not already a member.
            expect(collect($props['available'])->pluck('id')->all())
                ->toContain($outCourse->id)
                ->not->toContain($inCourse->id);
        });
});

it('refuses course changes from a viewer without manage', function () {
    // No such role holds product.view without manage in the seed, so simulate by
    // acting as an author (no product perms at all) — must be forbidden.
    $product = Product::factory()->create();
    [$course] = textbookCourse();

    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->post("/ops/products/{$product->id}/courses", ['course_id' => $course->id])
        ->assertForbidden();
});
