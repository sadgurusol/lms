<?php

use App\Assessments\QuestionType;
use App\Authorization\Roles;
use App\Models\Assessment;
use App\Models\CompGrant;
use App\Models\Product;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\User;
use App\Services\Assessments\AssessmentEditor;
use App\Services\Assessments\RecordAnswer;
use App\Services\Assessments\StartAttempt;
use App\Services\Assessments\SubmitAttempt;
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

it('requires authentication', function () {
    $this->getJson('/api/v1/me/dashboard')->assertUnauthorized();
});

it('summarises an entitled learner s courses and quiz performance', function () {
    // A quiz on a Topic, graded once.
    $node = $this->course->nodes()->whereHas('schemaLevel', fn ($q) => $q->where('name', 'Topic'))->firstOrFail();
    $assessment = Assessment::factory()->quiz()->create([
        'course_id' => $this->course->id,
        'course_node_id' => $node->id,
    ]);
    $bank = QuestionBank::factory()->create();
    $q = Question::factory()->ofType(QuestionType::TrueFalse, ['answer' => true])
        ->create(['question_bank_id' => $bank->id, 'default_points' => 10]);
    app(AssessmentEditor::class)->addQuestion($assessment, $q);

    $attempt = app(StartAttempt::class)->handle($assessment->fresh(), $this->learner);
    app(RecordAnswer::class)->handle($attempt, $attempt->question_order[0], ['answer' => true]);
    app(SubmitAttempt::class)->handle($attempt);

    $this->actingAs($this->learner)
        ->getJson('/api/v1/me/dashboard')
        ->assertOk()
        ->assertJsonPath('stats.courses_enrolled', 1)
        ->assertJsonPath('stats.quizzes_taken', 1)
        ->assertJsonPath('stats.quizzes_passed', 0) // a quiz has no pass mark
        // JSON renders 100.0 as 100; compare numerically.
        ->assertJsonPath('stats.average_quiz_percentage', fn ($v) => (float) $v === 100.0)
        ->assertJsonPath('courses.0.id', $this->course->id)
        ->assertJsonPath('recent_quizzes.0.percentage', fn ($v) => (float) $v === 100.0);
});

it('shows an empty dashboard for a learner with no courses', function () {
    $stranger = User::factory()->withRole(Roles::LEARNER)->create();

    $this->actingAs($stranger)
        ->getJson('/api/v1/me/dashboard')
        ->assertOk()
        ->assertJsonPath('stats.courses_enrolled', 0)
        ->assertJsonPath('stats.quizzes_taken', 0)
        ->assertJsonPath('stats.average_quiz_percentage', null)
        ->assertJsonCount(0, 'courses');
});
