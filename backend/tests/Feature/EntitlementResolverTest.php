<?php

use App\Entitlements\EntitlementCache;
use App\Entitlements\EntitlementResolver;
use App\Entitlements\Grant;
use App\Entitlements\GrantSource;
use App\Entitlements\Sources\CompGrantSource;
use App\Models\CompGrant;
use App\Models\Course;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\ManageProduct;

beforeEach(function () {
    [$this->course] = publishedTextbookCourse();
    $this->user = User::factory()->create();
    $this->resolver = fn () => app(EntitlementResolver::class);
});

/** An active product containing the given courses. */
function productWith(Course ...$courses): Product
{
    $product = Product::factory()->create();

    foreach ($courses as $course) {
        app(ManageProduct::class)->addCourse($product, $course);
    }

    return $product;
}

function compGrant(User $user, Product $product, ?Carbon\Carbon $endsAt = null): CompGrant
{
    return CompGrant::create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'reason' => CompGrant::REASON_REVIEWER,
        'starts_at' => now()->subMinute(),
        'ends_at' => $endsAt,
    ]);
}

/*
|--------------------------------------------------------------------------
| The core question
|--------------------------------------------------------------------------
*/

it('denies a user with no grant', function () {
    productWith($this->course);

    expect(($this->resolver)()->grantFor($this->user, $this->course))->toBeNull();
});

/** The M6 acceptance criterion: a reviewer reads a published course on a comp grant. */
it('grants access through a comp grant', function () {
    $product = productWith($this->course);
    compGrant($this->user, $product);

    $grant = ($this->resolver)()->grantFor($this->user, $this->course);

    expect($grant)->not->toBeNull()
        ->and($grant->source)->toBe(Grant::SOURCE_COMP)
        ->and($grant->clientId)->toBeNull();
});

it('denies access once a comp grant expires', function () {
    $product = productWith($this->course);
    compGrant($this->user, $product, endsAt: now()->addHour());

    expect(($this->resolver)()->entitles($this->user, $this->course))->toBeTrue();

    $this->travel(2)->hours();

    expect(($this->resolver)()->entitles($this->user, $this->course))->toBeFalse();
});

/**
 * A grant that lapses inside the five-minute cache window must lapse on time.
 * Two minutes is well within the TTL, so only the expiry riding along with the
 * cached grant can catch this.
 */
it('denies access the moment a grant lapses, without waiting for the cache to expire', function () {
    $product = productWith($this->course);
    compGrant($this->user, $product, endsAt: now()->addMinutes(2));

    expect(($this->resolver)()->entitles($this->user, $this->course))->toBeTrue();

    $this->travel(3)->minutes();

    expect(($this->resolver)()->entitles($this->user, $this->course))->toBeFalse();
});

it('denies access before a comp grant starts', function () {
    $product = productWith($this->course);
    CompGrant::create([
        'user_id' => $this->user->id,
        'product_id' => $product->id,
        'reason' => CompGrant::REASON_TRIAL,
        'starts_at' => now()->addDay(),
    ]);

    expect(($this->resolver)()->entitles($this->user, $this->course))->toBeFalse();
});

/** However entitled, there is nothing to read until the course is published. */
it('denies access to an unpublished course', function () {
    [$draft] = textbookCourse();
    $product = productWith($draft);
    compGrant($this->user, $product);

    expect(($this->resolver)()->entitles($this->user, $draft))->toBeFalse()
        ->and(($this->resolver)()->coursesFor($this->user))->toBeEmpty();
});

it('ignores a retired product', function () {
    $product = productWith($this->course);
    compGrant($this->user, $product);
    $product->update(['status' => 'retired']);

    expect(($this->resolver)()->entitles($this->user, $this->course))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Bundles: the reason the product layer exists
|--------------------------------------------------------------------------
*/

it('grants every course in a bundle from one grant', function () {
    [$second] = publishedTextbookCourse();

    $bundle = Product::factory()->bundle()->create();
    app(ManageProduct::class)->addCourse($bundle, $this->course);
    app(ManageProduct::class)->addCourse($bundle, $second);

    compGrant($this->user, $bundle);

    expect(($this->resolver)()->entitles($this->user, $this->course))->toBeTrue()
        ->and(($this->resolver)()->entitles($this->user, $second))->toBeTrue()
        ->and(($this->resolver)()->coursesFor($this->user))->toHaveCount(2);
});

/** Adding a course to a bundle grants it to every holder, at once. */
it('grants a newly bundled course to existing holders', function () {
    $bundle = Product::factory()->bundle()->create();
    app(ManageProduct::class)->addCourse($bundle, $this->course);
    compGrant($this->user, $bundle);

    [$newCourse] = publishedTextbookCourse();
    expect(($this->resolver)()->entitles($this->user, $newCourse))->toBeFalse();

    app(ManageProduct::class)->addCourse($bundle, $newCourse);

    // No per-user invalidation could have found this user. The global cache
    // version is what makes it correct.
    expect(($this->resolver)()->entitles($this->user, $newCourse))->toBeTrue();
});

it('revokes access when a course leaves a bundle', function () {
    $bundle = Product::factory()->bundle()->create();
    app(ManageProduct::class)->addCourse($bundle, $this->course);
    compGrant($this->user, $bundle);

    expect(($this->resolver)()->entitles($this->user, $this->course))->toBeTrue();

    app(ManageProduct::class)->removeCourse($bundle, $this->course);

    expect(($this->resolver)()->entitles($this->user, $this->course))->toBeFalse();
});

it('refuses to delete a course somebody paid for', function () {
    productWith($this->course);

    expectDatabaseRejection(
        fn () => DB::table('courses')->where('id', $this->course->id)->delete(),
        'violates foreign key constraint',
    );
});

/*
|--------------------------------------------------------------------------
| Caching and invalidation
|--------------------------------------------------------------------------
*/

it('busts the cache when a comp grant is created or revoked', function () {
    $product = productWith($this->course);

    expect(($this->resolver)()->entitles($this->user, $this->course))->toBeFalse();

    $grant = compGrant($this->user, $product);
    expect(($this->resolver)()->entitles($this->user, $this->course))->toBeTrue();

    $grant->delete();
    expect(($this->resolver)()->entitles($this->user, $this->course))->toBeFalse();
});

it('does not leak one user s grants into another s cache', function () {
    $product = productWith($this->course);
    compGrant($this->user, $product);

    $stranger = User::factory()->create();

    expect(($this->resolver)()->entitles($this->user, $this->course))->toBeTrue()
        ->and(($this->resolver)()->entitles($stranger, $this->course))->toBeFalse();
});

/** The same user under a client context is a different cache entry. */
it('keys the cache by client context', function () {
    $product = productWith($this->course);
    compGrant($this->user, $product);

    // CompGrantSource ignores the client context, so both resolve — but they
    // must not share a cache entry, or a client-scoped grant would bleed into
    // the user's B2C session.
    expect(($this->resolver)()->entitles($this->user, $this->course, clientId: null))->toBeTrue()
        ->and(($this->resolver)()->entitles($this->user, $this->course, clientId: 'abc-school'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Source ordering
|--------------------------------------------------------------------------
*/

/**
 * Attribution follows the session context, not the cheapest grant. When
 * subscriptions and client contracts land, a student launched from ABC School
 * must read under ABC's contract even if they also hold a personal
 * subscription — otherwise ABC's activity report silently omits them.
 */
it('returns the highest priority source when two cover the same product', function () {
    $product = productWith($this->course);
    compGrant($this->user, $product);

    $fakeClientSource = new class($product->id) implements GrantSource
    {
        public function __construct(private string $productId) {}

        public function name(): string
        {
            return Grant::SOURCE_CLIENT;
        }

        public function grantsFor(User $user, ?string $clientId): array
        {
            return $clientId === null ? [] : [
                $this->productId => new Grant(Grant::SOURCE_CLIENT, clientId: $clientId),
            ];
        }
    };

    $resolver = new EntitlementResolver(
        app(EntitlementCache::class),
        [$fakeClientSource, app(CompGrantSource::class)],
    );

    $b2c = $resolver->grantFor($this->user, $this->course, clientId: null);
    $b2b = $resolver->grantFor($this->user, $this->course, clientId: 'abc-school');

    expect($b2c->source)->toBe(Grant::SOURCE_COMP)
        ->and($b2b->source)->toBe(Grant::SOURCE_CLIENT)
        ->and($b2b->clientId)->toBe('abc-school');
});

/*
|--------------------------------------------------------------------------
| The catalogue
|--------------------------------------------------------------------------
*/

it('lists only entitled and published courses, alphabetically', function () {
    [$other] = publishedTextbookCourse();
    [$unentitled] = publishedTextbookCourse();

    $this->course->update(['title' => 'Beta']);
    $other->update(['title' => 'Alpha']);

    $bundle = Product::factory()->bundle()->create();
    app(ManageProduct::class)->addCourse($bundle, $this->course);
    app(ManageProduct::class)->addCourse($bundle, $other);
    productWith($unentitled);

    compGrant($this->user, $bundle);

    expect(($this->resolver)()->coursesFor($this->user)->pluck('title')->all())
        ->toBe(['Alpha', 'Beta']);
});

it('returns an empty catalogue for a user with nothing', function () {
    productWith($this->course);

    expect(($this->resolver)()->coursesFor($this->user))->toBeEmpty();
});
