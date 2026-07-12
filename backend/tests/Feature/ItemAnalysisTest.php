<?php

use App\Assessments\QuestionType;
use App\Authorization\Roles;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentQuestion;
use App\Models\AttemptAnswer;
use App\Models\CoursePublication;
use App\Models\Question;
use App\Models\User;
use App\Services\Assessments\QuestionStats;
use App\Support\FractionalIndex;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    [$this->course] = publishedTextbookCourse();
    $this->publication = CoursePublication::where('course_id', $this->course->id)->firstOrFail();

    $this->assessment = Assessment::factory()->quiz()->create(['course_id' => $this->course->id]);
    $this->stats = app(QuestionStats::class);
});

function addQuestion(float $points = 1.0): AssessmentQuestion
{
    $last = AssessmentQuestion::where('assessment_id', test()->assessment->id)
        ->orderByDesc('sort_key')->value('sort_key');

    return AssessmentQuestion::create([
        'assessment_id' => test()->assessment->id,
        'question_id' => Question::factory()->ofType(QuestionType::TrueFalse, ['answer' => true])->create()->id,
        'points' => $points,
        'sort_key' => FractionalIndex::between($last, null),
    ]);
}

/**
 * Record a graded attempt directly. The grading pipeline is tested elsewhere;
 * here we care only about what the view computes from the results.
 *
 * @param  array<string, bool>  $correctness  assessment_question_id => was it right
 */
function gradedAttempt(array $correctness, int $number): AssessmentAttempt
{
    $points = array_map(fn (bool $right) => $right ? 1.0 : 0.0, $correctness);
    $score = array_sum($points);

    $attempt = new AssessmentAttempt;
    $attempt->forceFill([
        'id' => (string) Str::uuid7(),
        'assessment_id' => test()->assessment->id,
        'publication_id' => test()->publication->id,
        'user_id' => User::factory()->create()->id,
        'attempt_number' => $number,
        'state' => AssessmentAttempt::GRADED,
        'question_order' => array_keys($correctness),
        'started_at' => now(),
        'submitted_at' => now(),
        'graded_at' => now(),
        'score' => $score,
        'max_score' => count($correctness),
    ])->save();

    foreach ($correctness as $aqId => $right) {
        AttemptAnswer::create([
            'attempt_id' => $attempt->id,
            'assessment_question_id' => $aqId,
            'response' => ['answer' => $right],
            'is_correct' => $right,
            'points_awarded' => $right ? 1.0 : 0.0,
        ]);
    }

    return $attempt;
}

/*
|--------------------------------------------------------------------------
| Facility
|--------------------------------------------------------------------------
*/

it('reports no stats for a question nobody has answered', function () {
    $aq = addQuestion();
    $this->stats->refresh();

    expect($this->stats->for($aq->question_id))->toBeNull();
});

it('computes facility as the fraction who answered correctly', function () {
    $q = addQuestion();
    $filler = addQuestion();

    // 3 of 4 learners get it right.
    gradedAttempt([$q->id => true, $filler->id => true], 1);
    gradedAttempt([$q->id => true, $filler->id => true], 2);
    gradedAttempt([$q->id => true, $filler->id => false], 3);
    gradedAttempt([$q->id => false, $filler->id => false], 4);

    $this->stats->refresh();
    $stats = $this->stats->for($q->question_id);

    expect($stats['attempts'])->toBe(4)
        ->and($stats['facility'])->toBe(0.75);
});

it('flags a question everybody gets right as too easy', function () {
    $q = addQuestion();
    $filler = addQuestion();

    foreach (range(1, 4) as $i) {
        gradedAttempt([$q->id => true, $filler->id => $i <= 2], $i);
    }

    $this->stats->refresh();

    expect($this->stats->for($q->question_id)['flags'])->toContain('too_easy');
});

it('flags a question nobody gets right as too hard', function () {
    $q = addQuestion();
    $filler = addQuestion();

    foreach (range(1, 4) as $i) {
        gradedAttempt([$q->id => false, $filler->id => $i <= 2], $i);
    }

    $this->stats->refresh();

    expect($this->stats->for($q->question_id)['flags'])->toContain('too_hard');
});

/*
|--------------------------------------------------------------------------
| Discrimination — the loudest signal in the bank
|--------------------------------------------------------------------------
*/

/** A good item: the learners who scored well on the test also got this right. */
it('reports positive discrimination for an item that separates learners', function () {
    $q = addQuestion();
    $a = addQuestion();
    $b = addQuestion();

    // Strong learners get everything; weak learners get nothing.
    gradedAttempt([$q->id => true, $a->id => true, $b->id => true], 1);
    gradedAttempt([$q->id => true, $a->id => true, $b->id => true], 2);
    gradedAttempt([$q->id => false, $a->id => false, $b->id => false], 3);
    gradedAttempt([$q->id => false, $a->id => false, $b->id => false], 4);

    $this->stats->refresh();
    $stats = $this->stats->for($q->question_id);

    expect($stats['discrimination'])->toBeGreaterThan(0.5)
        ->and($stats['flags'])->not->toContain('likely_miskeyed')
        ->and($stats['flags'])->not->toContain('weak_discrimination');
});

/**
 * The whole point of computing discrimination.
 *
 * On a miskeyed item, the learners who did best on the test do *worst* on this
 * question — they knew the real answer, and the key says otherwise. The
 * correlation goes negative, and nothing else in the system would notice.
 */
it('reports negative discrimination for a miskeyed question', function () {
    $miskeyed = addQuestion();
    $a = addQuestion();
    $b = addQuestion();

    // Strong learners (2/2 on the good items) are marked wrong on the miskeyed one.
    gradedAttempt([$miskeyed->id => false, $a->id => true, $b->id => true], 1);
    gradedAttempt([$miskeyed->id => false, $a->id => true, $b->id => true], 2);
    // Weak learners guess the keyed answer and are marked right.
    gradedAttempt([$miskeyed->id => true, $a->id => false, $b->id => false], 3);
    gradedAttempt([$miskeyed->id => true, $a->id => false, $b->id => false], 4);

    $this->stats->refresh();
    $stats = $this->stats->for($miskeyed->question_id);

    expect($stats['discrimination'])->toBeLessThan(0.0)
        ->and($stats['flags'])->toContain('likely_miskeyed');
});

it('flags an item that separates nobody', function () {
    $q = addQuestion();
    $a = addQuestion();

    // Correct/incorrect on $q is uncorrelated with total score.
    gradedAttempt([$q->id => true, $a->id => true], 1);
    gradedAttempt([$q->id => false, $a->id => true], 2);
    gradedAttempt([$q->id => true, $a->id => false], 3);
    gradedAttempt([$q->id => false, $a->id => false], 4);

    $this->stats->refresh();
    $stats = $this->stats->for($q->question_id);

    expect(abs($stats['discrimination']))->toBeLessThan(QuestionStats::WEAK_DISCRIMINATION)
        ->and($stats['flags'])->toContain('weak_discrimination');
});

/*
|--------------------------------------------------------------------------
| What the view excludes
|--------------------------------------------------------------------------
*/

/** An attempt awaiting a human is not a data point. */
it('ignores answers that no one has graded yet', function () {
    $q = addQuestion();
    $filler = addQuestion();

    gradedAttempt([$q->id => true, $filler->id => true], 1);

    $attempt = gradedAttempt([$q->id => false, $filler->id => false], 2);
    $attempt->answers()->update(['is_correct' => null, 'points_awarded' => null]);

    $this->stats->refresh();

    expect($this->stats->for($q->question_id)['attempts'])->toBe(1)
        ->and($this->stats->for($q->question_id)['facility'])->toBe(1.0);
});

it('ignores attempts that were never graded', function () {
    $q = addQuestion();
    $filler = addQuestion();

    gradedAttempt([$q->id => true, $filler->id => true], 1);
    gradedAttempt([$q->id => false, $filler->id => false], 2)
        ->forceFill(['state' => AssessmentAttempt::AWAITING_REVIEW])->save();

    $this->stats->refresh();

    expect($this->stats->for($q->question_id)['attempts'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Refresh and endpoint
|--------------------------------------------------------------------------
*/

/** Concurrent refresh keeps the stats screen readable while the view rebuilds. */
it('refreshes concurrently, which needs the unique index', function () {
    $index = DB::selectOne("SELECT indexname FROM pg_indexes WHERE tablename = 'question_stats'");

    expect($index->indexname)->toBe('question_stats_question_id_idx');

    $this->artisan('assessments:refresh-stats')->assertSuccessful();
});

it('serves stats to an author and refuses a learner', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $q = addQuestion();
    gradedAttempt([$q->id => true], 1);
    $this->stats->refresh();

    $this->actingAs(User::factory()->withRole(Roles::CONTENT_AUTHOR)->create())
        ->getJson("/api/v1/questions/{$q->question_id}/stats")
        ->assertOk()
        ->assertJsonPath('attempts', 1)
        ->assertJsonPath('facility', 1);

    $this->actingAs(User::factory()->withRole(Roles::LEARNER)->create())
        ->getJson("/api/v1/questions/{$q->question_id}/stats")
        ->assertForbidden();
});

/** A question nobody answered exists; it just has no data. Do not 404 it. */
it('returns an empty stat block for an unanswered question', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $q = addQuestion();
    $this->stats->refresh();

    $this->actingAs(User::factory()->withRole(Roles::CONTENT_AUTHOR)->create())
        ->getJson("/api/v1/questions/{$q->question_id}/stats")
        ->assertOk()
        ->assertJsonPath('attempts', 0)
        ->assertJsonPath('facility', null)
        ->assertJsonPath('flags', []);
});
