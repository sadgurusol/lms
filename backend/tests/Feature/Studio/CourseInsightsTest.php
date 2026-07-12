<?php

use App\Assessments\QuestionType;
use App\Authorization\Roles;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\CourseGrant;
use App\Models\CoursePublication;
use App\Models\NodeProgress;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\User;
use App\Services\Assessments\AssessmentEditor;
use App\Services\Assessments\RecordAnswer;
use App\Services\Assessments\StartAttempt;
use App\Services\Assessments\SubmitAttempt;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->course] = publishedTextbookCourse();
    $this->owner = staff(Roles::CONTENT_AUTHOR);
    grant($this->owner, $this->course, CourseGrant::OWNER);

    $this->publication = CoursePublication::where('course_id', $this->course->id)->firstOrFail();
    $tree = $this->publication->snapshot['tree'];
    $this->chapterId = $tree[0]['children'][0]['id'];
    $this->topicId = $tree[0]['children'][0]['children'][0]['id'];
});

/** Mark a learner as having completed the given publication nodes. */
function completeNodes(User $learner, string $publicationId, array $nodeIds, int $seconds = 300): void
{
    foreach ($nodeIds as $nodeId) {
        NodeProgress::create([
            'user_id' => $learner->id,
            'publication_id' => $publicationId,
            'course_node_id' => $nodeId,
            'state' => NodeProgress::COMPLETED,
            'seconds_spent' => $seconds,
            'completed_at' => now(),
        ]);
    }
}

/** Grade one true/false question for a learner, answering right or wrong. */
function gradeQuiz(Assessment $assessment, User $learner, bool $correct): void
{
    $attempt = app(StartAttempt::class)->handle($assessment->fresh(), $learner);
    app(RecordAnswer::class)->handle($attempt, $attempt->question_order[0], ['answer' => $correct]);
    app(SubmitAttempt::class)->handle($attempt);
}

function quizOnTopic(Course $course, string $topicNodeId): Assessment
{
    $node = $course->nodes()->whereHas('schemaLevel', fn ($q) => $q->where('name', 'Topic'))->firstOrFail();
    $assessment = Assessment::factory()->quiz()->create([
        'course_id' => $course->id,
        'course_node_id' => $node->id,
    ]);
    $bank = QuestionBank::factory()->create();
    $question = Question::factory()->ofType(QuestionType::TrueFalse, ['answer' => true])
        ->create(['question_bank_id' => $bank->id, 'default_points' => 10]);
    app(AssessmentEditor::class)->addQuestion($assessment, $question);

    return $assessment->fresh();
}

it('refuses insights to someone with no grant', function () {
    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->get("/studio/courses/{$this->course->id}/insights")
        ->assertForbidden();
});

it('reports an empty state before anyone has started', function () {
    $this->actingAs($this->owner)
        ->get("/studio/courses/{$this->course->id}/insights")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('courses/Insights')
            ->where('published', true)
            ->where('summary.learners', 0)
            ->has('learners', 0)
        );
});

it('aggregates completion, time, and quiz performance across the cohort', function () {
    $ahead = User::factory()->create();
    $behind = User::factory()->clientProvisioned()->create();

    completeNodes($ahead, $this->publication->id, [$this->chapterId, $this->topicId], seconds: 600); // 100%
    completeNodes($behind, $this->publication->id, [$this->chapterId], seconds: 120);                // 50%

    $quiz = quizOnTopic($this->course, $this->topicId);
    gradeQuiz($quiz, $ahead, correct: true);   // 100%
    gradeQuiz($quiz, $behind, correct: false); // 0% -> at risk

    $this->actingAs($this->owner)
        ->get("/studio/courses/{$this->course->id}/insights")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('courses/Insights')
            ->where('summary.learners', 2)
            ->where('summary.completed_course', 1)
            ->where('summary.average_completion', fn ($v) => (float) $v === 75.0)
            ->where('summary.at_risk', 1)
            ->where('summary.quizzes_graded', 2)
            ->where('summary.quiz_average', fn ($v) => (float) $v === 50.0)
            // A quiz has no pass mark, so no attempt counts as "passed".
            ->where('summary.pass_rate', fn ($v) => (float) $v === 0.0)
            ->has('score_distribution', 10)
            ->has('assessments', 1)
            ->where('assessments.0.attempts', 2)
            ->has('learners', 2)
            // The most at-risk learner sorts first.
            ->where('learners.0.at_risk', true)
        );
});

it('de-identifies learners and never leaks their name or email', function () {
    $learner = User::factory()->create(['name' => 'Priya Sharma', 'email' => 'priya@example.com']);
    completeNodes($learner, $this->publication->id, [$this->chapterId]);

    $response = $this->actingAs($this->owner)
        ->get("/studio/courses/{$this->course->id}/insights");

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('learners.0.ref', fn ($ref) => str_starts_with((string) $ref, 'L-'))
        ->where('learners.0.kind', 'direct')
        ->missing('learners.0.name')
        ->missing('learners.0.email')
    );

    expect($response->getContent())
        ->not->toContain('Priya Sharma')
        ->not->toContain('priya@example.com');
});

it('shows nothing to measure for an unpublished course', function () {
    [$draft] = textbookCourse();
    grant($this->owner, $draft, CourseGrant::OWNER);

    $this->actingAs($this->owner)
        ->get("/studio/courses/{$draft->id}/insights")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('published', false)
            ->where('summary.learners', 0)
        );
});
