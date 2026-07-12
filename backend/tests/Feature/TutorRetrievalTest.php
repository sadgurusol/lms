<?php

use App\Authorization\Roles;
use App\Jobs\EmbedPublicationJob;
use App\Models\CompGrant;
use App\Models\ContentBlock;
use App\Models\ContentEmbedding;
use App\Models\Course;
use App\Models\CourseGrant;
use App\Models\CoursePublication;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\ManageProduct;
use App\Services\Publishing\PublishCourse;
use App\Tutor\ContentEmbedder;
use App\Tutor\Retrieval;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * A deterministic stand-in for a real embedding model: a text maps to a vector
 * over a fixed vocabulary by keyword presence, so cosine ranking is predictable.
 *
 * @return list<float>
 */
function fakeVector(string $text): array
{
    $vocab = ['photosynthesis', 'sunlight', 'mitochondria', 'cell', 'gravity', 'force'];
    $lower = mb_strtolower($text);

    return array_map(fn (string $word) => str_contains($lower, $word) ? 1.0 : 0.0, $vocab);
}

/** Fake both providers the tutor talks to: Voyage (embeddings) and Anthropic (chat). */
function fakeTutorProviders(): void
{
    Http::fake([
        'api.voyageai.com/*' => function ($request) {
            $data = [];
            foreach ($request->data()['input'] as $i => $text) {
                $data[] = ['index' => $i, 'embedding' => fakeVector($text)];
            }

            return Http::response(['data' => $data]);
        },
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Here is how it works…']],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 30],
        ]),
    ]);
}

/**
 * A published course with two content nodes carrying distinct subjects.
 *
 * @return array{Course, User, string} course, author, chapter node id
 */
function courseWithTwoTopics(User $author): array
{
    [$course] = publishableCourseOwnedBy($author);

    $portable = fn (string $text) => ['format' => 'portable_text', 'body' => [[
        '_type' => 'block', 'style' => 'normal', 'markDefs' => [],
        'children' => [['_type' => 'span', 'text' => $text, 'marks' => []]],
    ]]];

    $chapter = $course->nodes()->whereHas('schemaLevel', fn ($q) => $q->where('name', 'Chapter'))->firstOrFail();
    $topic = $course->nodes()->whereHas('schemaLevel', fn ($q) => $q->where('name', 'Topic'))->firstOrFail();

    ContentBlock::where('course_node_id', $chapter->id)->first()
        ->update(['payload' => $portable('Photosynthesis converts sunlight into energy in plants.')]);
    ContentBlock::where('course_node_id', $topic->id)->first()
        ->update(['payload' => $portable('Mitochondria are the powerhouse of the cell.')]);

    app(PublishCourse::class)->handle($course->fresh(), $author);

    return [$course->fresh(), $author, $chapter->id];
}

/** publishableCourse, but grant OWNER to a specific user. */
function publishableCourseOwnedBy(User $author): array
{
    [$course] = publishableCourse();
    CourseGrant::create(['user_id' => $author->id, 'course_id' => $course->id, 'role' => 'owner']);

    return [$course, $author];
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config([
        'services.anthropic.key' => 'test-key',
        'services.voyage.key' => 'voyage-key',
        'services.voyage.model' => 'voyage-3',
    ]);
    $this->author = staff(Roles::CONTENT_AUTHOR);
});

it('embeds each content node on publish when configured', function () {
    fakeTutorProviders();
    [$course] = courseWithTwoTopics($this->author);

    $publication = CoursePublication::where('course_id', $course->id)->firstOrFail();

    expect(ContentEmbedding::where('publication_id', $publication->id)->count())->toBe(2);
});

it('ranks the section most relevant to the question first', function () {
    fakeTutorProviders();
    [$course, , $chapterId] = courseWithTwoTopics($this->author);
    $publication = CoursePublication::where('course_id', $course->id)->firstOrFail();

    $hits = app(Retrieval::class)->relevantNodes($publication, 'Explain photosynthesis to me', 2);

    // The photosynthesis chapter outranks the mitochondria topic.
    expect($hits[0]['id'])->toBe($chapterId)
        ->and($hits[0]['text'])->toContain('Photosynthesis');
});

it('injects the retrieved sections into the tutor prompt', function () {
    fakeTutorProviders();
    [$course] = courseWithTwoTopics($this->author);

    $learner = entitledLearner($course);
    $conversationId = test()->actingAs($learner, 'sanctum')
        ->postJson("/api/v1/me/courses/{$course->id}/tutor/conversations")->json('data.id');

    test()->actingAs($learner, 'sanctum')
        ->postJson("/api/v1/me/tutor/conversations/{$conversationId}/messages", ['content' => 'Tell me about photosynthesis'])
        ->assertOk()
        ->assertJsonPath('data.citations', fn ($c) => collect($c)->pluck('label')->isNotEmpty());

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'anthropic')) {
            return false;
        }

        return str_contains($request->data()['system'], 'Relevant sections')
            && str_contains($request->data()['system'], 'Photosynthesis converts sunlight');
    });
});

it('works without embeddings configured, grounding on the outline alone', function () {
    config(['services.voyage.key' => null]);
    Http::fake(['api.anthropic.com/*' => Http::response([
        'content' => [['type' => 'text', 'text' => 'Let us start with the basics.']],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ])]);

    [$course] = courseWithTwoTopics($this->author); // publishes with no embeddings

    expect(ContentEmbedding::count())->toBe(0);

    $learner = entitledLearner($course);
    $conversationId = test()->actingAs($learner, 'sanctum')
        ->postJson("/api/v1/me/courses/{$course->id}/tutor/conversations")->json('data.id');

    test()->actingAs($learner, 'sanctum')
        ->postJson("/api/v1/me/tutor/conversations/{$conversationId}/messages", ['content' => 'Hello'])
        ->assertOk();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'anthropic')
        && ! str_contains($request->data()['system'], 'Relevant sections'));
});

it('queues embedding off the publish request when configured', function () {
    Queue::fake();
    courseWithTwoTopics($this->author); // publishes

    Queue::assertPushed(EmbedPublicationJob::class);
});

it('does not queue embedding when embeddings are unconfigured', function () {
    config(['services.voyage.key' => null]);
    Queue::fake();
    courseWithTwoTopics($this->author);

    Queue::assertNotPushed(EmbedPublicationJob::class);
});

it('re-embeds a publication idempotently', function () {
    fakeTutorProviders();
    [$course] = courseWithTwoTopics($this->author);
    $publication = CoursePublication::where('course_id', $course->id)->firstOrFail();

    app(ContentEmbedder::class)->embed($publication);
    app(ContentEmbedder::class)->embed($publication);

    expect(ContentEmbedding::where('publication_id', $publication->id)->count())->toBe(2);
});

/** A learner entitled to a course via a comp grant. */
function entitledLearner(Course $course): User
{
    $learner = User::factory()->withRole(Roles::LEARNER)->create();
    $product = Product::factory()->create();
    app(ManageProduct::class)->addCourse($product, $course);
    CompGrant::create([
        'user_id' => $learner->id,
        'product_id' => $product->id,
        'reason' => CompGrant::REASON_TRIAL,
        'starts_at' => now()->subMinute(),
    ]);

    return $learner;
}
