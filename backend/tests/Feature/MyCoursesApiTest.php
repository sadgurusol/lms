<?php

use App\Authorization\Roles;
use App\Exceptions\NotEntitled;
use App\Models\CompGrant;
use App\Models\ContentBlock;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\ManageProduct;
use App\Services\Publishing\PublishCourse;
use App\Services\Tree\CourseTree;
use App\Support\FractionalIndex;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->course] = publishedTextbookCourse();
    $this->learner = User::factory()->withRole(Roles::LEARNER)->create();
    $this->product = Product::factory()->create();

    app(ManageProduct::class)->addCourse($this->product, $this->course);
});

function entitle(User $user, Product $product): CompGrant
{
    return CompGrant::create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'reason' => CompGrant::REASON_TRIAL,
        'starts_at' => now()->subMinute(),
    ]);
}

it('requires authentication', function () {
    $this->getJson('/api/v1/me/courses')->assertUnauthorized();
});

it('lists an entitled learner s courses', function () {
    entitle($this->learner, $this->product);

    $this->actingAs($this->learner)
        ->getJson('/api/v1/me/courses')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->course->id)
        ->assertJsonPath('data.0.publication_id', $this->course->fresh()->latest_publication_id);
});

it('returns an empty catalogue for an unentitled learner', function () {
    $this->actingAs($this->learner)
        ->getJson('/api/v1/me/courses')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

/*
|--------------------------------------------------------------------------
| The 403, and its shape
|--------------------------------------------------------------------------
*/

/**
 * Never 404 a course the caller isn't entitled to: it makes "does this exist?"
 * indistinguishable from "may I read it?", and support cannot triage it.
 */
it('answers 403, not 404, for a course the learner is not entitled to', function () {
    $this->actingAs($this->learner)
        ->getJson("/api/v1/me/courses/{$this->course->id}/content")
        ->assertForbidden()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('reason', NotEntitled::NO_GRANT)
        ->assertJsonPath('cta.kind', 'paywall')
        ->assertJsonPath('status', 403);
});

it('reports an unpublished course as not_published, with no paywall', function () {
    [$draft] = textbookCourse();
    app(ManageProduct::class)->addCourse($this->product, $draft);
    entitle($this->learner, $this->product);

    $this->actingAs($this->learner)
        ->getJson("/api/v1/me/courses/{$draft->id}/content")
        ->assertForbidden()
        ->assertJsonPath('reason', NotEntitled::NOT_PUBLISHED)
        ->assertJsonMissingPath('cta');
});

it('still 404s a course id that does not exist', function () {
    $this->actingAs($this->learner)
        ->getJson('/api/v1/me/courses/'.Str::uuid7().'/content')
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| The snapshot
|--------------------------------------------------------------------------
*/

it('serves the published snapshot to an entitled learner', function () {
    entitle($this->learner, $this->product);

    $response = $this->actingAs($this->learner)
        ->getJson("/api/v1/me/courses/{$this->course->id}/content")
        ->assertOk()
        ->assertJsonPath('publication.number', 1)
        ->assertJsonPath('course.id', $this->course->id)
        ->assertJsonPath('tree.0.label', 'Part I')
        ->assertJsonPath('tree.0.children.0.label', 'Chapter 1: Chapter One');

    expect($response->headers->get('ETag'))->not->toBeNull();
});

it('answers 304 when the client already holds the current snapshot', function () {
    entitle($this->learner, $this->product);

    $etag = $this->course->fresh()->latestPublication->snapshot_etag;

    $this->actingAs($this->learner)
        ->withHeader('If-None-Match', $etag)
        ->getJson("/api/v1/me/courses/{$this->course->id}/content")
        ->assertStatus(304);
});

it('serves a new snapshot after a republish, so a stale etag no longer matches', function () {
    entitle($this->learner, $this->product);

    $oldEtag = $this->course->fresh()->latestPublication->snapshot_etag;

    // The author adds a topic and an admin republishes.
    $course = $this->course->fresh();
    $chapter = $course->nodes()->where('title', 'Chapter One')->firstOrFail();

    $topic = app(CourseTree::class)
        ->createNode($course, $chapter->schemaLevel->childLevels()->firstOrFail(), 'Topic Two', $chapter);

    ContentBlock::create([
        'course_node_id' => $topic->id,
        'type' => 'rich_text',
        'sort_key' => FractionalIndex::between(null, null),
        'payload' => ['format' => 'portable_text', 'body' => []],
    ]);

    app(PublishCourse::class)->handle($course->fresh(), User::factory()->create());

    $newEtag = $this->course->fresh()->latestPublication->snapshot_etag;
    expect($newEtag)->not->toBe($oldEtag);

    // The learner's cached etag is now stale: they get the new snapshot.
    $this->actingAs($this->learner)
        ->withHeader('If-None-Match', $oldEtag)
        ->getJson("/api/v1/me/courses/{$this->course->id}/content")
        ->assertOk()
        ->assertJsonPath('publication.number', 2)
        ->assertJsonCount(2, 'tree.0.children.0.children')
        ->assertHeader('ETag', '"'.$newEtag.'"');
});

/*
|--------------------------------------------------------------------------
| The M6 acceptance criterion
|--------------------------------------------------------------------------
*/

it('lets a content reviewer read a published course on a comp grant', function () {
    $reviewer = User::factory()->withRole(Roles::CONTENT_REVIEWER)->create();

    CompGrant::create([
        'user_id' => $reviewer->id,
        'product_id' => $this->product->id,
        'reason' => CompGrant::REASON_REVIEWER,
        'starts_at' => now()->subMinute(),
    ]);

    $this->actingAs($reviewer)
        ->getJson("/api/v1/me/courses/{$this->course->id}/content")
        ->assertOk();

    // And a user with no grant gets a paywall, never a 404.
    $this->actingAs(User::factory()->withRole(Roles::LEARNER)->create())
        ->getJson("/api/v1/me/courses/{$this->course->id}/content")
        ->assertForbidden()
        ->assertJsonPath('cta.kind', 'paywall');
});
