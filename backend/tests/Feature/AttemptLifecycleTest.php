<?php

use App\Assessments\QuestionType;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentQuestion;
use App\Models\AttemptAnswer;
use App\Models\Question;
use App\Models\User;
use App\Services\Assessments\ExpireAttempts;
use App\Services\Assessments\GradeAnswer;
use App\Services\Assessments\RecordAnswer;
use App\Services\Assessments\StartAttempt;
use App\Services\Assessments\SubmitAttempt;
use App\Services\Tree\CourseTree;
use App\Support\FractionalIndex;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    [$this->course] = publishedTextbookCourse();
    $this->learner = User::factory()->create();
});

/** Attach a question to an assessment, appending in order. */
function attach(Assessment $assessment, Question $question, float $points = 10.0): AssessmentQuestion
{
    $last = AssessmentQuestion::where('assessment_id', $assessment->id)
        ->orderByDesc('sort_key')->value('sort_key');

    $aq = AssessmentQuestion::create([
        'assessment_id' => $assessment->id,
        'question_id' => $question->id,
        'points' => $points,
        'sort_key' => FractionalIndex::between($last, null),
    ]);

    $assessment->syncTotalPoints();

    return $aq;
}

function trueFalseQuestion(bool $answer = true): Question
{
    return Question::factory()->ofType(QuestionType::TrueFalse, ['answer' => $answer])->create();
}

/** A quiz with `$count` true/false questions, all answer=true. */
function quizWith(int $count, array $settings = []): array
{
    $assessment = Assessment::factory()->quiz($settings)->create(['course_id' => test()->course->id]);
    $questions = [];

    for ($i = 0; $i < $count; $i++) {
        $questions[] = attach($assessment, trueFalseQuestion());
    }

    return [$assessment->fresh(), $questions];
}

function start(Assessment $a): AssessmentAttempt
{
    return app(StartAttempt::class)->handle($a, test()->learner);
}

function answer(AssessmentAttempt $attempt, string $aqId, array $response): AttemptAnswer
{
    return app(RecordAnswer::class)->handle($attempt->fresh(), $aqId, $response);
}

function submit(AssessmentAttempt $attempt): AssessmentAttempt
{
    return app(SubmitAttempt::class)->handle($attempt->fresh());
}

/*
|--------------------------------------------------------------------------
| Starting
|--------------------------------------------------------------------------
*/

it('refuses to start an attempt on an unpublished course', function () {
    [$course] = textbookCourse();
    $assessment = Assessment::factory()->create(['course_id' => $course->id]);
    attach($assessment, trueFalseQuestion());

    expect(fn () => start($assessment->fresh()))
        ->toThrow(RuntimeException::class, 'has not been published');
});

it('refuses to start an assessment with no questions', function () {
    $assessment = Assessment::factory()->create(['course_id' => $this->course->id]);

    expect(fn () => start($assessment))->toThrow(RuntimeException::class, 'no questions');
});

it('attributes the attempt to the publication the learner is reading', function () {
    [$assessment] = quizWith(2);

    expect(start($assessment)->publication_id)->toBe($this->course->fresh()->latest_publication_id);
});

it('freezes the question order at start', function () {
    [$assessment, $qs] = quizWith(4, ['shuffle_questions' => true]);

    $attempt = start($assessment);
    $order = $attempt->question_order;

    expect($order)->toHaveCount(4)
        ->and($order)->toEqualCanonicalizing(collect($qs)->pluck('id')->all())
        // Reading it back gives the same order: the shuffle is not recomputed.
        ->and($attempt->fresh()->question_order)->toBe($order);
});

it('gives two learners different question orders but each a stable one', function () {
    [$assessment] = quizWith(6, ['shuffle_questions' => true]);
    $other = User::factory()->create();

    $mine = start($assessment)->question_order;
    $theirs = app(StartAttempt::class)->handle($assessment, $other)->question_order;

    expect($mine)->not->toBe($theirs)
        ->and($mine)->toEqualCanonicalizing($theirs);
});

it('resumes an in-progress attempt rather than starting a second one', function () {
    [$assessment] = quizWith(2);

    $first = start($assessment);
    $again = start($assessment);

    expect($again->id)->toBe($first->id)
        ->and(AssessmentAttempt::count())->toBe(1);
});

it('enforces max_attempts', function () {
    [$assessment] = quizWith(1, ['max_attempts' => 1]);

    submit(start($assessment));

    expect(fn () => start($assessment->fresh()))
        ->toThrow(RuntimeException::class, 'used all 1 attempt');
});

/*
|--------------------------------------------------------------------------
| Question pooling
|--------------------------------------------------------------------------
*/

it('draws a random subset when a pool size is set', function () {
    [$assessment] = quizWith(10, ['question_pool_size' => 4]);

    expect(start($assessment)->question_order)->toHaveCount(4);
});

/** Pooling selects; it must not reorder what the author arranged. */
it('keeps authored order when pooling without shuffling', function () {
    [$assessment, $qs] = quizWith(8, ['question_pool_size' => 4, 'shuffle_questions' => false]);

    $order = start($assessment)->question_order;
    $authored = collect($qs)->pluck('id')->all();

    $positions = array_map(fn (string $id) => array_search($id, $authored, true), $order);
    $sorted = $positions;
    sort($sorted);

    expect($positions)->toBe($sorted);
});

it('refuses a pool larger than the question set', function () {
    [$assessment] = quizWith(3, ['question_pool_size' => 5]);

    expect(fn () => start($assessment))->toThrow(RuntimeException::class, 'pool of only 3');
});

/*
|--------------------------------------------------------------------------
| Answering
|--------------------------------------------------------------------------
*/

it('upserts an answer idempotently, so replaying an outbox is safe', function () {
    [$assessment, $qs] = quizWith(1);
    $attempt = start($assessment);

    answer($attempt, $qs[0]->id, ['answer' => false]);
    answer($attempt, $qs[0]->id, ['answer' => true]);

    expect(AttemptAnswer::count())->toBe(1)
        ->and(AttemptAnswer::first()->response)->toBe(['answer' => true]);
});

it('refuses an answer to a question outside the attempt', function () {
    [$assessment] = quizWith(1);
    $otherAssessment = Assessment::factory()->create(['course_id' => $this->course->id]);
    $foreign = attach($otherAssessment, trueFalseQuestion());

    $attempt = start($assessment);

    expect(fn () => answer($attempt, $foreign->id, ['answer' => true]))
        ->toThrow(RuntimeException::class, 'not part of this attempt');
});

/** The database backstop for I13, in case the service check is ever bypassed. */
it('has a trigger refusing an answer from another assessment', function () {
    [$assessment] = quizWith(1);
    $otherAssessment = Assessment::factory()->create(['course_id' => $this->course->id]);
    $foreign = attach($otherAssessment, trueFalseQuestion());

    $attempt = start($assessment);

    expectDatabaseRejection(
        fn () => AttemptAnswer::create([
            'attempt_id' => $attempt->id,
            'assessment_question_id' => $foreign->id,
            'response' => ['answer' => true],
        ]),
        "outside the attempt's assessment",
    );
});

it('refuses answers after the time limit passes', function () {
    [$assessment, $qs] = quizWith(1, ['time_limit_s' => 60]);
    $attempt = start($assessment);

    $this->travel(3)->minutes();

    expect(fn () => answer($attempt, $qs[0]->id, ['answer' => true]))
        ->toThrow(RuntimeException::class, 'time limit');
});

/** A learner whose phone reconnects seconds late must not lose the answer. */
it('accepts an answer inside the offline grace window', function () {
    [$assessment, $qs] = quizWith(1, ['time_limit_s' => 60]);
    $attempt = start($assessment);

    $this->travel(90)->seconds();   // 30s past expiry, inside the 60s grace

    expect(answer($attempt, $qs[0]->id, ['answer' => true])->exists)->toBeTrue();
});

it('refuses backtracking when the assessment forbids it', function () {
    [$assessment] = quizWith(3, ['allow_backtrack' => false, 'shuffle_questions' => false]);
    $attempt = start($assessment);
    $order = $attempt->question_order;

    answer($attempt, $order[2], ['answer' => true]);

    expect(fn () => answer($attempt, $order[0], ['answer' => true]))
        ->toThrow(RuntimeException::class, 'cannot return to an earlier question');
});

it('clamps a device clock from the future', function () {
    [$assessment, $qs] = quizWith(1);
    $attempt = start($assessment);

    $answer = app(RecordAnswer::class)->handle(
        $attempt, $qs[0]->id, ['answer' => true], now()->addYear(),
    );

    expect($answer->answered_at->isBefore(now()->addMinutes(6)))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Submitting and grading
|--------------------------------------------------------------------------
*/

it('auto-grades a fully objective attempt', function () {
    [$assessment, $qs] = quizWith(2, ['pass_percentage' => 50]);
    $attempt = start($assessment);

    answer($attempt, $qs[0]->id, ['answer' => true]);    // correct
    answer($attempt, $qs[1]->id, ['answer' => false]);   // wrong

    $attempt = submit($attempt);

    expect($attempt->state)->toBe(AssessmentAttempt::GRADED)
        ->and((float) $attempt->score)->toBe(10.0)
        ->and((float) $attempt->max_score)->toBe(20.0)
        ->and($attempt->passed)->toBeTrue();
});

it('leaves passed null when the assessment has no pass mark', function () {
    [$assessment, $qs] = quizWith(1);
    $attempt = start($assessment);
    answer($attempt, $qs[0]->id, ['answer' => true]);

    expect(submit($attempt)->passed)->toBeNull();
});

/** Item analysis must distinguish "shown and skipped" from "never shown". */
it('records skipped questions explicitly as zero', function () {
    [$assessment, $qs] = quizWith(3);
    $attempt = start($assessment);

    answer($attempt, $qs[0]->id, ['answer' => true]);
    $attempt = submit($attempt);

    expect($attempt->answers()->count())->toBe(3)
        ->and((float) $attempt->score)->toBe(10.0);

    $skipped = $attempt->answers()->where('assessment_question_id', $qs[1]->id)->first();
    expect($skipped->response)->toBe([])
        ->and($skipped->is_correct)->toBeFalse()
        ->and((float) $skipped->points_awarded)->toBe(0.0);
});

it('sends an attempt with an essay to awaiting_review', function () {
    $assessment = Assessment::factory()->quiz()->create(['course_id' => $this->course->id]);
    $tf = attach($assessment, trueFalseQuestion());
    $essay = attach($assessment, Question::factory()->ofType(QuestionType::Essay, ['rubric' => []])->create());

    $attempt = start($assessment->fresh());
    answer($attempt, $tf->id, ['answer' => true]);
    answer($attempt, $essay->id, ['text' => 'Because the past is simple.']);

    $attempt = submit($attempt);

    expect($attempt->state)->toBe(AssessmentAttempt::AWAITING_REVIEW)
        ->and($attempt->score)->toBeNull();
});

it('finalises once a human grades the last open answer', function () {
    $assessment = Assessment::factory()->quiz(['pass_percentage' => 50])->create(['course_id' => $this->course->id]);
    $tf = attach($assessment, trueFalseQuestion());
    $essay = attach($assessment, Question::factory()->ofType(QuestionType::Essay, ['rubric' => []])->create());

    $attempt = start($assessment->fresh());
    answer($attempt, $tf->id, ['answer' => true]);
    answer($attempt, $essay->id, ['text' => 'An essay.']);
    $attempt = submit($attempt);

    $teacher = User::factory()->create();
    $open = $attempt->answers()->whereNull('is_correct')->firstOrFail();

    $attempt = app(GradeAnswer::class)->handle($open, $teacher, 7.0, 'Good structure.');

    expect($attempt->state)->toBe(AssessmentAttempt::GRADED)
        ->and((float) $attempt->score)->toBe(17.0)
        ->and($attempt->passed)->toBeTrue()
        ->and($open->fresh()->grader_id)->toBe($teacher->id);
});

it('refuses to award more points than the question is worth', function () {
    $assessment = Assessment::factory()->quiz()->create(['course_id' => $this->course->id]);
    attach($assessment, Question::factory()->ofType(QuestionType::Essay, ['rubric' => []])->create(), 5.0);

    $attempt = start($assessment->fresh());
    $attempt = submit($attempt);

    $open = $attempt->answers()->whereNull('is_correct')->firstOrFail();

    expect(fn () => app(GradeAnswer::class)->handle($open, User::factory()->create(), 9.0))
        ->toThrow(RuntimeException::class, 'between 0 and 5');
});

it('refuses to submit twice', function () {
    [$assessment] = quizWith(1);
    $attempt = submit(start($assessment));

    expect(fn () => submit($attempt))->toThrow(RuntimeException::class, 'already graded');
});

/*
|--------------------------------------------------------------------------
| Expiry
|--------------------------------------------------------------------------
*/

/** An expired attempt is submitted and graded, never discarded. */
it('auto-submits and grades an expired attempt, preserving the learner s work', function () {
    [$assessment, $qs] = quizWith(2, ['time_limit_s' => 60, 'pass_percentage' => 50]);
    $attempt = start($assessment);
    answer($attempt, $qs[0]->id, ['answer' => true]);

    $this->travel(5)->minutes();

    expect(app(ExpireAttempts::class)->handle())->toBe(1);

    $attempt = $attempt->fresh();

    expect($attempt->state)->toBe(AssessmentAttempt::GRADED)
        ->and((float) $attempt->score)->toBe(10.0)
        ->and($attempt->meta['auto_submitted'])->toBeTrue();
});

it('does not expire an attempt inside the grace window', function () {
    [$assessment] = quizWith(1, ['time_limit_s' => 60]);
    start($assessment);

    $this->travel(90)->seconds();

    expect(app(ExpireAttempts::class)->handle())->toBe(0);
});

it('does not expire an untimed quiz', function () {
    [$assessment] = quizWith(1);
    start($assessment);

    $this->travel(10)->years();

    expect(app(ExpireAttempts::class)->handle())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Assessment attachment (I6)
|--------------------------------------------------------------------------
*/

it('refuses to attach an assessment to a level that forbids them', function () {
    [$course, $partLevel] = textbookCourse();
    $part = app(CourseTree::class)->createNode($course, $partLevel, 'Part');

    // Part does not allow assessments; Chapter and Topic do.
    expectDatabaseRejection(
        fn () => Assessment::factory()->create([
            'course_id' => $course->id,
            'course_node_id' => $part->id,
        ]),
        'does not permit assessments',
    );
});

it('refuses to attach an assessment to a node in another course', function () {
    [$otherCourse, $partLevel, $chapterLevel] = textbookCourse();
    $tree = app(CourseTree::class);
    $part = $tree->createNode($otherCourse, $partLevel, 'Part');
    $chapter = $tree->createNode($otherCourse, $chapterLevel, 'Chapter', $part);

    expectDatabaseRejection(
        fn () => Assessment::factory()->create([
            'course_id' => $this->course->id,
            'course_node_id' => $chapter->id,
        ]),
        'belongs to a different course',
    );
});

it('refuses to delete a question that an assessment uses', function () {
    [$assessment, $qs] = quizWith(1);

    expectDatabaseRejection(
        fn () => DB::table('questions')->where('id', $qs[0]->question_id)->delete(),
        'violates foreign key constraint',
    );
});
