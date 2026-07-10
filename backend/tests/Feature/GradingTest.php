<?php

use App\Assessments\Grading\GradeResult;
use App\Assessments\Grading\GraderRegistry;
use App\Assessments\QuestionType;
use App\Models\Question;

function gradeQ(Question $question, array $response, float $points = 10.0): GradeResult
{
    return app(GraderRegistry::class)->for($question->questionType())->grade($question->fresh(), $response, $points);
}

it('has a grader for every question type', function () {
    expect(GraderRegistry::coveredTypes())->toEqualCanonicalizing(QuestionType::names());
});

/*
|--------------------------------------------------------------------------
| Single choice / true-false / numeric
|--------------------------------------------------------------------------
*/

it('grades a single-choice question', function () {
    $q = Question::factory()->ofType(QuestionType::McqSingle)
        ->withOptions([['text' => 'ran', 'correct' => true], ['text' => 'run']])
        ->create();

    $right = $q->options->firstWhere('is_correct', true);
    $wrong = $q->options->firstWhere('is_correct', false);

    expect(gradeQ($q, ['option_id' => $right->id])->points)->toBe(10.0)
        ->and(gradeQ($q, ['option_id' => $wrong->id])->isCorrect)->toBeFalse()
        ->and(gradeQ($q, [])->isCorrect)->toBeFalse();
});

it('grades true/false', function () {
    $q = Question::factory()->ofType(QuestionType::TrueFalse, ['answer' => true])->create();

    expect(gradeQ($q, ['answer' => true])->isCorrect)->toBeTrue()
        ->and(gradeQ($q, ['answer' => false])->isCorrect)->toBeFalse()
        ->and(gradeQ($q, [])->isCorrect)->toBeFalse();
});

it('grades a numeric answer within tolerance', function () {
    $q = Question::factory()->ofType(QuestionType::Numeric, ['answer' => 3.14, 'tolerance' => 0.01])->create();

    expect(gradeQ($q, ['answer' => 3.14])->isCorrect)->toBeTrue()
        ->and(gradeQ($q, ['answer' => 3.15])->isCorrect)->toBeTrue()
        ->and(gradeQ($q, ['answer' => 3.16])->isCorrect)->toBeFalse()
        ->and(gradeQ($q, ['answer' => 'not a number'])->isCorrect)->toBeFalse();
});

/** A tolerance of zero still needs an epsilon; never compare floats for equality. */
it('grades an exact numeric answer without float equality', function () {
    $q = Question::factory()->ofType(QuestionType::Numeric, ['answer' => 0.3, 'tolerance' => 0])->create();

    expect(gradeQ($q, ['answer' => 0.1 + 0.2])->isCorrect)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Multiple choice
|--------------------------------------------------------------------------
*/

function mcqMulti(string $scoring): Question
{
    return Question::factory()
        ->ofType(QuestionType::McqMulti, ['scoring' => $scoring])
        ->withOptions([
            ['text' => 'a', 'correct' => true],
            ['text' => 'b', 'correct' => true],
            ['text' => 'c'],
            ['text' => 'd'],
        ])
        ->create();
}

it('grades multi-choice all or nothing', function () {
    $q = mcqMulti('all_or_nothing');
    $ids = $q->options->pluck('id', 'body.text');

    expect(gradeQ($q, ['option_ids' => [$ids['a'], $ids['b']]])->points)->toBe(10.0)
        ->and(gradeQ($q, ['option_ids' => [$ids['a']]])->points)->toBe(0.0)
        ->and(gradeQ($q, ['option_ids' => [$ids['a'], $ids['b'], $ids['c']]])->points)->toBe(0.0);
});

it('grades multi-choice with partial credit', function () {
    $q = mcqMulti('partial');
    $ids = $q->options->pluck('id', 'body.text');

    // 2 correct of 2, 0 wrong => full marks
    expect(gradeQ($q, ['option_ids' => [$ids['a'], $ids['b']]])->points)->toBe(10.0)
        // 1 correct, 0 wrong => (1-0)/2
        ->and(gradeQ($q, ['option_ids' => [$ids['a']]])->points)->toBe(5.0)
        // 2 correct, 1 wrong => (2-1)/2
        ->and(gradeQ($q, ['option_ids' => [$ids['a'], $ids['b'], $ids['c']]])->points)->toBe(5.0);
});

/** Selecting everything must never beat selecting nothing. */
it('never awards negative marks for over-selection', function () {
    $q = mcqMulti('partial');
    $all = $q->options->pluck('id')->all();

    $result = gradeQ($q, ['option_ids' => $all]);

    expect($result->points)->toBe(0.0)
        ->and($result->isCorrect)->toBeFalse();
});

it('flags a miskeyed multi-choice question for a human rather than failing the learner', function () {
    $q = Question::factory()->ofType(QuestionType::McqMulti)
        ->withOptions([['text' => 'a'], ['text' => 'b']])   // nothing marked correct
        ->create();

    expect(gradeQ($q, ['option_ids' => []])->needsHumanGrading())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Fill in the blank
|--------------------------------------------------------------------------
*/

it('grades fill-in-the-blank with per-blank partial credit', function () {
    $q = Question::factory()->ofType(QuestionType::FillBlank, [
        'blanks' => [
            ['id' => 'b1', 'accept' => ['ran', 'run'], 'case_sensitive' => false],
            ['id' => 'b2', 'accept' => ['ate']],
        ],
    ])->create();

    expect(gradeQ($q, ['blanks' => ['b1' => 'RAN', 'b2' => 'ate']])->points)->toBe(10.0)
        ->and(gradeQ($q, ['blanks' => ['b1' => 'ran', 'b2' => 'eaten']])->points)->toBe(5.0)
        ->and(gradeQ($q, ['blanks' => []])->points)->toBe(0.0);
});

it('honours case sensitivity on a blank', function () {
    $q = Question::factory()->ofType(QuestionType::FillBlank, [
        'blanks' => [['id' => 'b1', 'accept' => ['Paris'], 'case_sensitive' => true]],
    ])->create();

    expect(gradeQ($q, ['blanks' => ['b1' => 'Paris']])->isCorrect)->toBeTrue()
        ->and(gradeQ($q, ['blanks' => ['b1' => 'paris']])->isCorrect)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Matching
|--------------------------------------------------------------------------
*/

function matchQuestion(): Question
{
    return Question::factory()->ofType(QuestionType::Match)
        ->withOptions([
            ['text' => 'Ran', 'match_key' => 'past'],
            ['text' => 'Simple past', 'match_key' => 'past'],
            ['text' => 'Runs', 'match_key' => 'present'],
            ['text' => 'Simple present', 'match_key' => 'present'],
        ])
        ->create();
}

it('grades matching with partial credit per pair', function () {
    $q = matchQuestion();
    $id = $q->options->pluck('id', 'body.text');

    expect(gradeQ($q, ['pairs' => [
        $id['Ran'] => $id['Simple past'],
        $id['Runs'] => $id['Simple present'],
    ]])->points)->toBe(10.0);

    expect(gradeQ($q, ['pairs' => [$id['Ran'] => $id['Simple past']]])->points)->toBe(5.0);

    expect(gradeQ($q, ['pairs' => [$id['Ran'] => $id['Simple present']]])->points)->toBe(0.0);
});

it('refuses to let a learner pair an option with itself', function () {
    $q = matchQuestion();
    $id = $q->options->pluck('id', 'body.text');

    expect(gradeQ($q, ['pairs' => [$id['Ran'] => $id['Ran']]])->points)->toBe(0.0);
});

/*
|--------------------------------------------------------------------------
| Ordering
|--------------------------------------------------------------------------
*/

it('grades an exact ordering', function () {
    $q = Question::factory()->ofType(QuestionType::Ordering, [
        'correct_order' => ['a', 'b', 'c'], 'scoring' => 'exact',
    ])->create();

    expect(gradeQ($q, ['order' => ['a', 'b', 'c']])->points)->toBe(10.0)
        ->and(gradeQ($q, ['order' => ['a', 'c', 'b']])->points)->toBe(0.0);
});

it('grades an ordering by kendall tau', function () {
    $q = Question::factory()->ofType(QuestionType::Ordering, [
        'correct_order' => ['a', 'b', 'c', 'd'], 'scoring' => 'kendall_tau',
    ])->create();

    // 6 pairs. Perfect order => 6/6.
    expect(gradeQ($q, ['order' => ['a', 'b', 'c', 'd']])->points)->toBe(10.0);

    // One adjacent swap breaks exactly one pair => 5/6.
    expect(round(gradeQ($q, ['order' => ['b', 'a', 'c', 'd']])->points, 2))->toBe(8.33);

    // Fully reversed => 0/6.
    expect(gradeQ($q, ['order' => ['d', 'c', 'b', 'a']])->points)->toBe(0.0);
});

it('rejects an ordering that omits or invents items', function () {
    $q = Question::factory()->ofType(QuestionType::Ordering, [
        'correct_order' => ['a', 'b', 'c'], 'scoring' => 'kendall_tau',
    ])->create();

    expect(gradeQ($q, ['order' => ['a', 'b']])->isCorrect)->toBeFalse()
        ->and(gradeQ($q, ['order' => ['a', 'b', 'z']])->isCorrect)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Short answer: the asymmetric grader
|--------------------------------------------------------------------------
*/

it('auto-grades an exact short answer', function () {
    $q = Question::factory()->ofType(QuestionType::ShortAnswer, ['accept' => ['photosynthesis']])->create();

    expect(gradeQ($q, ['text' => '  Photosynthesis '])->isCorrect)->toBeTrue();
});

it('auto-grades a near miss as correct when fuzzy matching is on', function () {
    $q = Question::factory()->ofType(QuestionType::ShortAnswer, [
        'accept' => ['photosynthesis'], 'fuzzy' => true, 'max_distance' => 2,
    ])->create();

    expect(gradeQ($q, ['text' => 'photosynthisis'])->isCorrect)->toBeTrue();
});

/**
 * The rule that matters: a non-match is never auto-graded wrong. A machine that
 * marks a spelling slip zero at 2am gets overruled by a teacher at 9am.
 */
it('sends an unrecognised short answer to a human rather than marking it wrong', function () {
    $q = Question::factory()->ofType(QuestionType::ShortAnswer, [
        'accept' => ['photosynthesis'], 'fuzzy' => true, 'max_distance' => 2,
    ])->create();

    $result = gradeQ($q, ['text' => 'respiration']);

    expect($result->needsHumanGrading())->toBeTrue()
        ->and($result->isCorrect)->toBeNull();
});

it('marks a blank short answer wrong without troubling a human', function () {
    $q = Question::factory()->ofType(QuestionType::ShortAnswer, ['accept' => ['x']])->create();

    expect(gradeQ($q, ['text' => '   '])->isCorrect)->toBeFalse();
});

it('always sends an essay to a human', function () {
    $q = Question::factory()->ofType(QuestionType::Essay, ['rubric' => []])->create();

    expect(gradeQ($q, ['text' => str_repeat('word ', 500)])->needsHumanGrading())->toBeTrue();
});
