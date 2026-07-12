<?php

use App\Assessments\QuestionType;
use App\Authorization\Roles;
use App\Models\Assessment;
use App\Models\Client;
use App\Models\CompGrant;
use App\Models\CoursePublication;
use App\Models\Product;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\TutorConversation;
use App\Models\TutorMessage;
use App\Models\User;
use App\Services\Assessments\AssessmentEditor;
use App\Services\Catalog\ManageProduct;
use App\Services\Publishing\PublishCourse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['services.anthropic.key' => 'test-key', 'services.anthropic.model' => 'claude-sonnet-5']);

    [$this->course] = publishedTextbookCourse();
    $this->publication = CoursePublication::where('course_id', $this->course->id)->firstOrFail();
    $this->topicId = $this->publication->snapshot['tree'][0]['children'][0]['children'][0]['id'];

    $this->learner = User::factory()->withRole(Roles::LEARNER)->create();
    $product = Product::factory()->create();
    app(ManageProduct::class)->addCourse($product, $this->course);
    CompGrant::create([
        'user_id' => $this->learner->id,
        'product_id' => $product->id,
        'reason' => CompGrant::REASON_TRIAL,
        'starts_at' => now()->subMinute(),
    ]);
});

/** Stub the Anthropic Messages API with a fixed reply. */
function fakeTutor(string $text = 'Let us reason through it together. What do you already know?'): void
{
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => $text]],
            'usage' => ['input_tokens' => 120, 'output_tokens' => 40],
        ], 200),
    ]);
}

function startConversation(User $learner, string $courseId): string
{
    return test()->actingAs($learner, 'sanctum')
        ->postJson("/api/v1/me/courses/{$courseId}/tutor/conversations")
        ->assertCreated()
        ->json('data.id');
}

/** Stub the Anthropic streaming endpoint with a two-token SSE reply. */
function fakeTutorStream(): void
{
    $frame = fn (string $json) => "data: {$json}\n\n";
    $sse = $frame('{"type":"message_start","message":{"usage":{"input_tokens":50}}}')
        .$frame('{"type":"content_block_delta","delta":{"type":"text_delta","text":"Hello"}}')
        .$frame('{"type":"content_block_delta","delta":{"type":"text_delta","text":" there"}}')
        .$frame('{"type":"message_delta","usage":{"output_tokens":8}}')
        .$frame('{"type":"message_stop"}');

    Http::fake(['api.anthropic.com/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);
}

it('requires authentication', function () {
    $this->postJson("/api/v1/me/courses/{$this->course->id}/tutor/conversations")->assertUnauthorized();
});

it('refuses the tutor to a learner with no entitlement, with 403 not 404', function () {
    $stranger = User::factory()->withRole(Roles::LEARNER)->create();

    $this->actingAs($stranger, 'sanctum')
        ->postJson("/api/v1/me/courses/{$this->course->id}/tutor/conversations")
        ->assertForbidden();
});

it('answers a question grounded in the course material', function () {
    fakeTutor();
    $conversationId = startConversation($this->learner, $this->course->id);

    $this->actingAs($this->learner, 'sanctum')
        ->postJson("/api/v1/me/tutor/conversations/{$conversationId}/messages", [
            'content' => 'Can you explain this topic?',
            'focus_node_id' => $this->topicId,
        ])
        ->assertOk()
        ->assertJsonPath('data.role', 'assistant')
        ->assertJsonPath('data.content', 'Let us reason through it together. What do you already know?')
        ->assertJsonPath('data.citations.0.id', $this->topicId);

    // The prompt sent to the model carries the course outline as grounding.
    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_contains($body['system'], 'COURSE MATERIAL')
            && str_contains($body['system'], 'Topic One')
            && $body['messages'][0]['content'] === 'Can you explain this topic?';
    });

    // Both turns are persisted.
    expect(TutorMessage::where('role', 'user')->count())->toBe(1)
        ->and(TutorMessage::where('role', 'assistant')->count())->toBe(1);
});

it('never puts assessment questions into the tutor prompt', function () {
    fakeTutor();

    // A quiz on the Topic, with a distinctive stem that must not leak.
    $node = $this->course->nodes()->whereHas('schemaLevel', fn ($q) => $q->where('name', 'Topic'))->firstOrFail();
    $assessment = Assessment::factory()->quiz()->create(['course_id' => $this->course->id, 'course_node_id' => $node->id]);
    $bank = QuestionBank::factory()->create();
    $question = Question::factory()->ofType(QuestionType::TrueFalse, ['answer' => true])->create([
        'question_bank_id' => $bank->id,
        'stem' => ['format' => 'portable_text', 'body' => [[
            '_type' => 'block', 'children' => [['_type' => 'span', 'text' => 'SECRET_QUIZ_STEM_XYZ']],
        ]]],
    ]);
    app(AssessmentEditor::class)->addQuestion($assessment, $question);
    app(PublishCourse::class)->handle($this->course->fresh(), $this->learner);

    $conversationId = startConversation($this->learner, $this->course->fresh()->id);
    $this->actingAs($this->learner, 'sanctum')
        ->postJson("/api/v1/me/tutor/conversations/{$conversationId}/messages", ['content' => 'What is the quiz answer?'])
        ->assertOk();

    Http::assertSent(fn ($request) => ! str_contains($request->data()['system'], 'SECRET_QUIZ_STEM_XYZ'));
});

it('replays prior turns as conversation history', function () {
    fakeTutor();
    $conversationId = startConversation($this->learner, $this->course->id);

    $ask = fn (string $q) => $this->actingAs($this->learner, 'sanctum')
        ->postJson("/api/v1/me/tutor/conversations/{$conversationId}/messages", ['content' => $q])
        ->assertOk();

    $ask('First question');
    $ask('Second question');

    // The second call carries the whole prior exchange, oldest-first.
    Http::assertSent(function ($request) {
        $messages = $request->data()['messages'];

        return count($messages) === 3
            && $messages[0] === ['role' => 'user', 'content' => 'First question']
            && $messages[1]['role'] === 'assistant'
            && $messages[2] === ['role' => 'user', 'content' => 'Second question'];
    });
});

it('keeps a conversation private to its owner', function () {
    fakeTutor();
    $conversationId = startConversation($this->learner, $this->course->id);
    $intruder = User::factory()->withRole(Roles::LEARNER)->create();

    $this->actingAs($intruder, 'sanctum')
        ->getJson("/api/v1/me/tutor/conversations/{$conversationId}")
        ->assertNotFound();

    $this->actingAs($intruder, 'sanctum')
        ->postJson("/api/v1/me/tutor/conversations/{$conversationId}/messages", ['content' => 'hi'])
        ->assertNotFound();
});

it('streams a reply token by token and persists the assembled message', function () {
    fakeTutorStream();
    $conversationId = startConversation($this->learner, $this->course->id);

    $response = $this->actingAs($this->learner, 'sanctum')
        ->post("/api/v1/me/tutor/conversations/{$conversationId}/stream", ['content' => 'Explain this topic']);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/event-stream');

    $body = $response->streamedContent();
    expect($body)->toContain('data: {"delta":"Hello"}')
        ->and($body)->toContain('data: {"delta":" there"}')
        ->and($body)->toContain('event: done');

    // The streamed tokens are assembled and persisted as one assistant message.
    expect(TutorMessage::where('role', 'assistant')->sole()->content)->toBe('Hello there');
});

it('reports a mid-stream failure as an SSE error frame', function () {
    Http::fake(['api.anthropic.com/*' => Http::response('nope', 500)]);
    $conversationId = startConversation($this->learner, $this->course->id);

    $response = $this->actingAs($this->learner, 'sanctum')
        ->post("/api/v1/me/tutor/conversations/{$conversationId}/stream", ['content' => 'hi']);

    $response->assertOk();
    expect($response->streamedContent())->toContain('event: error');
});

/*
|--------------------------------------------------------------------------
| Polish: B2B toggle and cost budget
|--------------------------------------------------------------------------
*/

/** Authenticate the learner with a token that carries a B2B client context. */
function actingUnderClient(User $learner, Client $client): void
{
    $token = $learner->createToken('test');
    $token->accessToken->forceFill(['client_id' => $client->id])->save();
    test()->withToken($token->plainTextToken);
}

it('lets a B2B client turn the tutor off for its learners', function () {
    fakeTutor();
    $client = Client::factory()->create();
    $client->setAiTutorEnabled(false);
    actingUnderClient($this->learner, $client);

    $this->postJson("/api/v1/me/courses/{$this->course->id}/tutor/conversations")
        ->assertForbidden()
        ->assertJsonPath('message', 'Your institution has turned off the AI tutor.');
});

it('still serves a B2B client whose tutor is left enabled', function () {
    fakeTutor();
    $client = Client::factory()->create(); // enabled by default
    actingUnderClient($this->learner, $client);

    $this->postJson("/api/v1/me/courses/{$this->course->id}/tutor/conversations")->assertCreated();
});

it('reports usage and remaining budget', function () {
    config(['tutor.monthly_token_budget' => 1000]);
    fakeTutor();
    $conversationId = startConversation($this->learner, $this->course->id);

    $this->actingAs($this->learner, 'sanctum')
        ->postJson("/api/v1/me/tutor/conversations/{$conversationId}/messages", ['content' => 'hi'])
        ->assertOk();

    // The faked reply reports 120 input + 40 output = 160 tokens spent.
    $this->actingAs($this->learner, 'sanctum')
        ->getJson('/api/v1/me/tutor/usage')
        ->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.budget', 1000)
        ->assertJsonPath('data.used', 160)
        ->assertJsonPath('data.remaining', 840);
});

it('refuses a message once the monthly token budget is spent', function () {
    config(['tutor.monthly_token_budget' => 100]); // below a single 160-token reply
    fakeTutor();
    $conversationId = startConversation($this->learner, $this->course->id);

    // Checked before generation, so the first message (nothing spent yet) lands…
    $this->actingAs($this->learner, 'sanctum')
        ->postJson("/api/v1/me/tutor/conversations/{$conversationId}/messages", ['content' => 'first'])
        ->assertOk();

    // …and the next is refused, the budget now spent.
    $this->actingAs($this->learner, 'sanctum')
        ->postJson("/api/v1/me/tutor/conversations/{$conversationId}/messages", ['content' => 'second'])
        ->assertStatus(429);
});

it('toggles the tutor for a client from the ops console', function () {
    $client = Client::factory()->create();

    $this->actingAs(staff(Roles::ADMIN))
        ->patch("/ops/clients/{$client->id}/ai-tutor", ['enabled' => false])
        ->assertSessionHas('success');

    expect($client->fresh()->aiTutorEnabled())->toBeFalse();
});

it('titles a conversation from its first message and returns the transcript', function () {
    fakeTutor();
    $conversationId = startConversation($this->learner, $this->course->id);

    $this->actingAs($this->learner, 'sanctum')
        ->postJson("/api/v1/me/tutor/conversations/{$conversationId}/messages", ['content' => 'How does photosynthesis work?'])
        ->assertOk();

    expect(TutorConversation::find($conversationId)->title)->toBe('How does photosynthesis work?');

    $this->actingAs($this->learner, 'sanctum')
        ->getJson("/api/v1/me/tutor/conversations/{$conversationId}")
        ->assertOk()
        ->assertJsonPath('data.messages.0.role', 'user')
        ->assertJsonPath('data.messages.1.role', 'assistant')
        ->assertJsonCount(2, 'data.messages');
});
