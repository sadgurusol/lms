<?php

use App\Models\CompGrant;
use App\Models\ContentBlock;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\ManageProduct;
use App\Services\Publishing\PublishCourse;
use App\Services\Search\SearchCourses;
use App\Services\Tree\CourseTree;
use App\Support\FractionalIndex;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    RateLimiter::clear('search');

    [$this->course, $this->partLevel, $this->chapterLevel, $this->topicLevel] = textbookCourse();
    $tree = app(CourseTree::class);

    $part = $tree->createNode($this->course, $this->partLevel, 'Mechanics');
    $chapter = $tree->createNode($this->course, $this->chapterLevel, 'Kinematics of Rigid Bodies', $part);
    ContentBlock::create([
        'course_node_id' => $chapter->id, 'type' => 'rich_text',
        'sort_key' => FractionalIndex::between(null, null),
        'payload' => ['format' => 'portable_text', 'body' => []],
    ]);
    $topic = $tree->createNode($this->course, $this->topicLevel, 'Angular momentum', $chapter);
    ContentBlock::create([
        'course_node_id' => $topic->id, 'type' => 'rich_text',
        'sort_key' => FractionalIndex::between(null, null),
        'payload' => ['format' => 'portable_text', 'body' => []],
    ]);
    $topic->update(['summary' => 'Conservation of angular momentum in rotating systems.']);

    app(PublishCourse::class)->handle($this->course->fresh(), User::factory()->create());
    $this->course->refresh();

    $this->product = Product::factory()->create();
    app(ManageProduct::class)->addCourse($this->product, $this->course);

    $this->learner = User::factory()->create();
});

function entitleLearner(User $user, Product $product): void
{
    CompGrant::create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'reason' => CompGrant::REASON_TRIAL,
        'starts_at' => now()->subMinute(),
    ]);
}

function search(User $user, string $query): array
{
    return app(SearchCourses::class)->handle($user, $query);
}

/*
|--------------------------------------------------------------------------
| Search is scoped by entitlement
|--------------------------------------------------------------------------
*/

/**
 * A search endpoint that returns node titles from courses the caller cannot read
 * is a content leak. "Chapter 7: Kinematics of Rigid Bodies" *is* the product.
 */
it('returns nothing to a learner who is not entitled', function () {
    expect(search($this->learner, 'kinematics'))->toBe([]);

    $this->actingAs($this->learner)
        ->getJson('/api/v1/search?q=kinematics')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('finds nodes in an entitled course', function () {
    entitleLearner($this->learner, $this->product);

    $results = search($this->learner, 'kinematics');

    expect($results)->toHaveCount(1)
        ->and($results[0]['title'])->toBe('Kinematics of Rigid Bodies')
        ->and($results[0]['course']['id'])->toBe($this->course->id);
});

it('searches summaries as well as titles', function () {
    entitleLearner($this->learner, $this->product);

    $results = search($this->learner, 'conservation rotating');

    expect(collect($results)->pluck('title'))->toContain('Angular momentum');
});

/** pg_trgm earns its index: a typo still finds the chapter. */
it('tolerates a typo through trigram similarity', function () {
    entitleLearner($this->learner, $this->product);

    $results = search($this->learner, 'Kinematcs of Rigid Bodies');

    expect(collect($results)->pluck('title'))->toContain('Kinematics of Rigid Bodies');
});

it('does not leak an unpublished course, however entitled', function () {
    entitleLearner($this->learner, $this->product);

    [$draft, $partLevel] = textbookCourse();
    app(CourseTree::class)->createNode($draft, $partLevel, 'Kinematics secret draft');
    app(ManageProduct::class)->addCourse($this->product, $draft);

    $titles = collect(search($this->learner, 'kinematics'))->pluck('title');

    expect($titles)->not->toContain('Kinematics secret draft');
});

it('rejects an empty or too-short query', function () {
    entitleLearner($this->learner, $this->product);

    expect(search($this->learner, '  '))->toBe([]);

    $this->actingAs($this->learner)->getJson('/api/v1/search?q=k')->assertStatus(422);
    $this->actingAs($this->learner)->getJson('/api/v1/search')->assertStatus(422);
});

it('excludes a soft-deleted node', function () {
    entitleLearner($this->learner, $this->product);

    $this->course->nodes()->where('title', 'Angular momentum')->firstOrFail()->delete();

    expect(collect(search($this->learner, 'angular'))->pluck('title'))->not->toContain('Angular momentum');
});

/*
|--------------------------------------------------------------------------
| Rate limits
|--------------------------------------------------------------------------
*/

it('throttles search per user', function () {
    entitleLearner($this->learner, $this->product);

    foreach (range(1, 30) as $i) {
        $this->actingAs($this->learner)->getJson('/api/v1/search?q=kinematics')->assertOk();
    }

    $this->actingAs($this->learner)
        ->getJson('/api/v1/search?q=kinematics')
        ->assertStatus(429);

    // A different learner is unaffected: the limit is per user, not global.
    $other = User::factory()->create();
    $this->actingAs($other)->getJson('/api/v1/search?q=kinematics')->assertOk();
});

/**
 * Verifying a signature is precisely the work we do not want an attacker to
 * command unboundedly, and the client is unknown until it verifies — so the
 * launch limit is keyed by IP.
 */
it('throttles launch attempts by ip', function () {
    RateLimiter::clear('launch');

    foreach (range(1, 60) as $i) {
        $this->post('/api/v1/launch', ['launch_token' => 'garbage'])->assertStatus(401);
    }

    $this->post('/api/v1/launch', ['launch_token' => 'garbage'])->assertStatus(429);
});

it('throttles checkout', function () {
    foreach (range(1, 10) as $i) {
        $this->actingAs($this->learner)
            ->postJson('/api/v1/me/subscriptions', ['plan_code' => 'nope'])
            ->assertStatus(404);
    }

    $this->actingAs($this->learner)
        ->postJson('/api/v1/me/subscriptions', ['plan_code' => 'nope'])
        ->assertStatus(429);
});

/** A provider retrying a burst must not be throttled into a parked stream. */
it('does not throttle the payment webhook', function () {
    config()->set('payments.razorpay.webhook_secret', 'shh');

    foreach (range(1, 40) as $i) {
        $body = json_encode(['event' => 'settlement.processed', 'created_at' => now()->timestamp]);

        $this->call('POST', '/api/v1/webhooks/razorpay', server: [
            'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, 'shh'),
            'HTTP_X_RAZORPAY_EVENT_ID' => "evt_{$i}",
            'CONTENT_TYPE' => 'application/json',
        ], content: $body)->assertStatus(202);
    }
});
