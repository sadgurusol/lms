<?php

use App\Models\CompGrant;
use App\Models\CoursePublication;
use App\Models\NodeProgress;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\ManageProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    [$this->course] = publishedTextbookCourse();
    $this->publication = CoursePublication::where('course_id', $this->course->id)->firstOrFail();

    $tree = $this->publication->snapshot['tree'];
    $this->chapterId = $tree[0]['children'][0]['id'];
    $this->topicId = $tree[0]['children'][0]['children'][0]['id'];

    $this->learner = User::factory()->create();
    $this->product = Product::factory()->create();
    app(ManageProduct::class)->addCourse($this->product, $this->course);

    CompGrant::create([
        'user_id' => $this->learner->id,
        'product_id' => $this->product->id,
        'reason' => CompGrant::REASON_TRIAL,
        'starts_at' => now()->subMinute(),
    ]);
});

/**
 * POST a batch to the outbox flush endpoint.
 *
 * @return TestResponse<JsonResponse>
 */
function flushOutbox(array $events): TestResponse
{
    return test()->actingAs(test()->learner)
        ->postJson('/api/v1/me/progress', ['events' => $events]);
}

function progressEvent(string $nodeId, array $extra = []): array
{
    return ['publication_id' => test()->publication->id, 'node_id' => $nodeId, ...$extra];
}

/*
|--------------------------------------------------------------------------
| Reading progress
|--------------------------------------------------------------------------
*/

it('requires authentication', function () {
    $this->getJson("/api/v1/me/courses/{$this->course->id}/progress")->assertUnauthorized();
    $this->postJson('/api/v1/me/progress', ['events' => []])->assertUnauthorized();
});

it('returns an empty progress summary before anything is read', function () {
    $this->actingAs($this->learner)
        ->getJson("/api/v1/me/courses/{$this->course->id}/progress")
        ->assertOk()
        ->assertJsonPath('percent', 0)
        ->assertJsonPath('total_nodes', 2)
        ->assertJsonPath('completed_nodes', 0)
        ->assertJsonCount(0, 'nodes');
});

it('refuses progress to a learner with no entitlement, with 403 not 404', function () {
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->getJson("/api/v1/me/courses/{$this->course->id}/progress")
        ->assertForbidden()
        ->assertJsonPath('cta.kind', 'paywall');
});

/*
|--------------------------------------------------------------------------
| Flushing an outbox
|--------------------------------------------------------------------------
*/

it('accepts a batch flush and reports each event', function () {
    flushOutbox([
        progressEvent($this->chapterId, ['state' => 'completed', 'seconds_spent' => 60]),
        progressEvent($this->topicId, ['state' => 'in_progress', 'seconds_spent' => 90, 'last_position' => 88]),
    ])
        ->assertStatus(202)
        ->assertJsonPath('results.0.status', 'accepted')
        ->assertJsonPath('results.1.status', 'accepted');

    $this->actingAs($this->learner)
        ->getJson("/api/v1/me/courses/{$this->course->id}/progress")
        ->assertOk()
        ->assertJsonPath('percent', 50)
        ->assertJsonPath('seconds_spent', 150);
});

/**
 * One malformed event must not reject a batch containing an hour of a learner's
 * work. Partial success is the normal case, and the client drains its outbox
 * from the per-event results.
 */
it('accepts the good events in a batch and rejects only the bad one', function () {
    $response = flushOutbox([
        progressEvent($this->chapterId, ['state' => 'completed']),
        progressEvent(Str::uuid7()->toString()),                       // node not in this publication
        progressEvent($this->topicId, ['state' => 'completed']),
    ])->assertStatus(202);

    $response->assertJsonPath('results.0.status', 'accepted')
        ->assertJsonPath('results.1.status', 'rejected')
        ->assertJsonPath('results.2.status', 'accepted');

    expect($response->json('results.1.reason'))->toContain('not part of this publication')
        ->and(NodeProgress::count())->toBe(2);
});

it('replays an outbox safely', function () {
    $events = [
        progressEvent($this->chapterId, ['state' => 'completed', 'seconds_spent' => 60]),
        progressEvent($this->topicId, ['state' => 'in_progress', 'seconds_spent' => 90]),
    ];

    flushOutbox($events)->assertStatus(202);
    flushOutbox($events)->assertStatus(202);
    flushOutbox($events)->assertStatus(202);

    expect(NodeProgress::count())->toBe(2)
        ->and((int) NodeProgress::sum('seconds_spent'))->toBe(150);
});

/*
|--------------------------------------------------------------------------
| The hole a batch endpoint invites
|--------------------------------------------------------------------------
*/

/**
 * `publication_id` arrives in the request body. Without an entitlement check it
 * is an unauthenticated pointer into any course in the system.
 */
it('refuses to write progress against a publication the learner is not entitled to', function () {
    [$otherCourse] = publishedTextbookCourse();
    $otherPublication = CoursePublication::where('course_id', $otherCourse->id)->firstOrFail();
    $otherNodeId = $otherPublication->snapshot['tree'][0]['children'][0]['id'];

    $response = flushOutbox([[
        'publication_id' => $otherPublication->id,
        'node_id' => $otherNodeId,
        'state' => 'completed',
    ]])->assertStatus(202);

    expect($response->json('results.0.status'))->toBe('rejected')
        ->and(NodeProgress::count())->toBe(0);
});

it('rejects an unknown publication id', function () {
    $response = flushOutbox([[
        'publication_id' => Str::uuid7()->toString(),
        'node_id' => $this->topicId,
    ]])->assertStatus(202);

    expect($response->json('results.0.status'))->toBe('rejected');
});

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

it('validates the batch envelope', function () {
    flushOutbox([])->assertStatus(422);

    $this->actingAs($this->learner)
        ->postJson('/api/v1/me/progress', ['events' => [['node_id' => 'not-a-uuid']]])
        ->assertStatus(422);

    $this->actingAs($this->learner)
        ->postJson('/api/v1/me/progress', [
            'events' => [progressEvent($this->topicId, ['seconds_spent' => -1])],
        ])
        ->assertStatus(422);
});

it('rejects an oversized batch rather than accepting an unbounded one', function () {
    $events = array_fill(0, 501, progressEvent($this->topicId));

    $this->actingAs($this->learner)
        ->postJson('/api/v1/me/progress', ['events' => $events])
        ->assertStatus(422);
});

it('rejects an unknown state at validation, before it reaches the service', function () {
    flushOutbox([progressEvent($this->topicId, ['state' => 'mastered'])])->assertStatus(422);
});
