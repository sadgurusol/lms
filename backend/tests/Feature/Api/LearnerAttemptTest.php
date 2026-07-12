<?php

use App\Assessments\QuestionType;
use App\Authorization\Roles;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\CompGrant;
use App\Models\Product;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\User;
use App\Services\Assessments\AssessmentEditor;
use App\Services\Catalog\ManageProduct;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->course] = publishedTextbookCourse();
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

/**
 * A quiz of single-answer MCQs on the published course, each worth 10 points.
 * Returns the assessment and the map question_id => correct option_id.
 *
 * @return array{Assessment, array<string, string>}
 */
function quizOnCourse(array $settings = [], int $questions = 2): array
{
    $node = test()->course->nodes()->whereHas('schemaLevel', fn ($q) => $q->where('name', 'Topic'))->firstOrFail();
    $assessment = Assessment::factory()->quiz($settings)->create([
        'course_id' => test()->course->id,
        'course_node_id' => $node->id,
    ]);

    $bank = QuestionBank::factory()->create();
    $editor = app(AssessmentEditor::class);
    $correct = [];

    for ($i = 0; $i < $questions; $i++) {
        $q = Question::factory()->ofType(QuestionType::McqSingle)
            ->withOptions([
                ['text' => "Right {$i}", 'correct' => true],
                ['text' => "Wrong {$i}", 'correct' => false],
            ])
            ->create(['question_bank_id' => $bank->id, 'default_points' => 10]);

        $editor->addQuestion($assessment, $q);
        $correct[$q->id] = $q->options->firstWhere('is_correct', true)->id;
    }

    return [$assessment->fresh(), $correct];
}

/*
|--------------------------------------------------------------------------
| Discovery
|--------------------------------------------------------------------------
*/

it('requires authentication', function () {
    [$assessment] = quizOnCourse();
    $this->postJson("/api/v1/me/assessments/{$assessment->id}/attempts")->assertUnauthorized();
});

it('lists assessments on an entitled course with my state', function () {
    [$assessment] = quizOnCourse();

    $this->actingAs($this->learner)
        ->getJson("/api/v1/me/courses/{$this->course->id}/assessments")
        ->assertOk()
        ->assertJsonPath('data.0.id', $assessment->id)
        ->assertJsonPath('data.0.question_count', 2)
        ->assertJsonPath('data.0.total_points', 20)
        ->assertJsonPath('data.0.my_state.attempts_used', 0)
        ->assertJsonPath('data.0.my_state.can_start', true);
});

it('refuses assessment discovery on an unentitled course', function () {
    [$assessment] = quizOnCourse();
    $stranger = User::factory()->withRole(Roles::LEARNER)->create();

    $this->actingAs($stranger)
        ->getJson("/api/v1/me/courses/{$this->course->id}/assessments")
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Starting, and the answer-key must not leak
|--------------------------------------------------------------------------
*/

it('starts an attempt and never leaks the answer key', function () {
    [$assessment] = quizOnCourse();

    $response = $this->actingAs($this->learner)
        ->postJson("/api/v1/me/assessments/{$assessment->id}/attempts")
        ->assertCreated()
        ->assertJsonPath('data.state', 'in_progress')
        ->assertJsonCount(2, 'data.questions');

    // Invariant I14: no correct-answer data reaches the learner mid-attempt.
    $body = $response->getContent();
    expect($body)->not->toContain('is_correct')
        ->and($body)->not->toContain('"grading"')
        ->and($body)->not->toContain('"explanation"');
});

it('resumes the same attempt instead of starting a second', function () {
    [$assessment] = quizOnCourse();

    $first = $this->actingAs($this->learner)
        ->postJson("/api/v1/me/assessments/{$assessment->id}/attempts")->json('data.id');
    $second = $this->actingAs($this->learner)
        ->postJson("/api/v1/me/assessments/{$assessment->id}/attempts")->json('data.id');

    expect($second)->toBe($first)
        ->and(AssessmentAttempt::count())->toBe(1);
});

it('refuses to start on an unentitled course', function () {
    [$assessment] = quizOnCourse();
    $stranger = User::factory()->withRole(Roles::LEARNER)->create();

    $this->actingAs($stranger)
        ->postJson("/api/v1/me/assessments/{$assessment->id}/attempts")
        ->assertForbidden();

    expect(AssessmentAttempt::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Answering and submitting
|--------------------------------------------------------------------------
*/

it('answers, submits, and grades the attempt', function () {
    [$assessment, $correct] = quizOnCourse();

    $payload = $this->actingAs($this->learner)
        ->postJson("/api/v1/me/assessments/{$assessment->id}/attempts")->json('data');

    // Answer the first question correctly, the second wrongly.
    foreach ($payload['questions'] as $i => $item) {
        $questionId = $item['question']['id'];
        $correctOptionId = $correct[$questionId];
        $wrongOptionId = collect($item['question']['options'])->pluck('id')->first(fn ($id) => $id !== $correctOptionId);

        $this->postJson("/api/v1/me/attempts/{$payload['id']}/answers", [
            'assessment_question_id' => $item['assessment_question_id'],
            'response' => ['option_id' => $i === 0 ? $correctOptionId : $wrongOptionId],
        ])->assertOk();
    }

    $result = $this->postJson("/api/v1/me/attempts/{$payload['id']}/submit")
        ->assertOk()
        ->assertJsonPath('data.state', 'graded')
        ->assertJsonPath('data.score', 10)
        ->assertJsonPath('data.max_score', 20)
        ->json('data');

    // A quiz has no pass mark, so passed stays null.
    expect($result['passed'])->toBeNull();
});

it('reveals the answer key only after submitting when the setting allows it', function () {
    // Quiz default show_answers = after_submit.
    [$assessment, $correct] = quizOnCourse();

    $attemptId = $this->actingAs($this->learner)
        ->postJson("/api/v1/me/assessments/{$assessment->id}/attempts")->json('data.id');
    $this->postJson("/api/v1/me/attempts/{$attemptId}/submit");

    // Now graded, the key is allowed through.
    $graded = $this->getJson("/api/v1/me/attempts/{$attemptId}")->assertOk();
    expect($graded->json('data.answers_revealed'))->toBeTrue()
        ->and($graded->getContent())->toContain('is_correct');
});

it('withholds the answer key when show_answers is never', function () {
    [$assessment] = quizOnCourse(['show_answers' => 'never']);

    $attemptId = $this->actingAs($this->learner)
        ->postJson("/api/v1/me/assessments/{$assessment->id}/attempts")->json('data.id');
    $this->postJson("/api/v1/me/attempts/{$attemptId}/submit");

    $graded = $this->getJson("/api/v1/me/attempts/{$attemptId}")->assertOk();
    expect($graded->json('data.answers_revealed'))->toBeFalse()
        ->and($graded->getContent())->not->toContain('is_correct');
});

/*
|--------------------------------------------------------------------------
| Rules the server enforces
|--------------------------------------------------------------------------
*/

it('caps attempts at max_attempts', function () {
    [$assessment] = quizOnCourse(['max_attempts' => 1]);

    $id = $this->actingAs($this->learner)
        ->postJson("/api/v1/me/assessments/{$assessment->id}/attempts")->json('data.id');
    $this->postJson("/api/v1/me/attempts/{$id}/submit");

    // The one attempt is used; a second start is refused.
    $this->postJson("/api/v1/me/assessments/{$assessment->id}/attempts")
        ->assertStatus(422)
        ->assertJsonPath('message', 'You have used all 1 attempt(s) at this assessment.');
});

it('refuses backtracking when the assessment forbids it', function () {
    [$assessment] = quizOnCourse(['allow_backtrack' => false]);

    $payload = $this->actingAs($this->learner)
        ->postJson("/api/v1/me/assessments/{$assessment->id}/attempts")->json('data');

    // Answer the second question (advance), then try to answer the first.
    $second = $payload['questions'][1]['assessment_question_id'];
    $first = $payload['questions'][0]['assessment_question_id'];

    $this->postJson("/api/v1/me/attempts/{$payload['id']}/answers", [
        'assessment_question_id' => $second, 'response' => ['option_id' => null],
    ])->assertOk();

    $this->postJson("/api/v1/me/attempts/{$payload['id']}/answers", [
        'assessment_question_id' => $first, 'response' => ['option_id' => null],
    ])->assertStatus(422);
});

it('will not answer a submitted attempt', function () {
    [$assessment] = quizOnCourse();
    $payload = $this->actingAs($this->learner)
        ->postJson("/api/v1/me/assessments/{$assessment->id}/attempts")->json('data');
    $this->postJson("/api/v1/me/attempts/{$payload['id']}/submit");

    $this->postJson("/api/v1/me/attempts/{$payload['id']}/answers", [
        'assessment_question_id' => $payload['questions'][0]['assessment_question_id'],
        'response' => ['option_id' => null],
    ])->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| Ownership
|--------------------------------------------------------------------------
*/

it('404s another learner s attempt', function () {
    [$assessment] = quizOnCourse();
    $attemptId = $this->actingAs($this->learner)
        ->postJson("/api/v1/me/assessments/{$assessment->id}/attempts")->json('data.id');

    // A different entitled learner must not see it.
    $other = User::factory()->withRole(Roles::LEARNER)->create();
    $product = Product::factory()->create();
    app(ManageProduct::class)->addCourse($product, $this->course);
    CompGrant::create(['user_id' => $other->id, 'product_id' => $product->id, 'reason' => CompGrant::REASON_TRIAL, 'starts_at' => now()->subMinute()]);

    $this->actingAs($other)->getJson("/api/v1/me/attempts/{$attemptId}")->assertNotFound();
});
