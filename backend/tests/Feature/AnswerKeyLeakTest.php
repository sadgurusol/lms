<?php

use App\Assessments\AssessmentSettings;
use App\Assessments\QuestionType;
use App\Http\Resources\QuestionViewerResource;
use App\Models\Assessment;
use App\Models\AssessmentQuestion;
use App\Models\Question;
use App\Models\User;
use App\Services\Assessments\StartAttempt;

/**
 * Invariant I14: correct-answer data never reaches a learner before submission.
 *
 * These are the tests that catch the regression someone introduces in month
 * nine by adding `->load('options')` to a controller, or by swapping this
 * hand-built payload for a JsonResource that serialises the model.
 */
beforeEach(function () {
    [$this->course] = publishedTextbookCourse();
    $this->learner = User::factory()->create();
});

/** Every key, at every depth. */
function keysDeep(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }

    $keys = [];

    foreach ($value as $key => $child) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        $keys = [...$keys, ...keysDeep($child)];
    }

    return $keys;
}

function attemptFor(Question $question, array $settings = []): array
{
    $assessment = Assessment::factory()->quiz($settings)->create(['course_id' => test()->course->id]);

    $aq = AssessmentQuestion::create([
        'assessment_id' => $assessment->id,
        'question_id' => $question->id,
        'points' => 10,
        'sort_key' => 'V',
    ]);

    $attempt = app(StartAttempt::class)->handle($assessment->fresh(), test()->learner);

    return [$attempt, $aq];
}

it('finds keys at every depth, so the leak assertions below mean something', function () {
    expect(keysDeep(['a' => 1, 'b' => [['is_correct' => true]]]))
        ->toContain('is_correct')
        ->toContain('a');
});

it('never emits is_correct to a learner mid-attempt', function () {
    $question = Question::factory()->ofType(QuestionType::McqSingle)
        ->withOptions([
            ['text' => 'ran', 'correct' => true],
            ['text' => 'run'],
            ['text' => 'running'],
        ])
        ->create();

    [$attempt] = attemptFor($question);

    $payload = QuestionViewerResource::forAttempt($question->fresh(), $attempt, 10.0);

    expect(keysDeep($payload))
        ->not->toContain('is_correct')
        ->not->toContain('grading')
        ->not->toContain('explanation')
        ->not->toContain('feedback')
        ->not->toContain('match_key');
});

it('never emits the grading key for types whose answer lives there', function () {
    foreach ([
        [QuestionType::TrueFalse, ['answer' => true]],
        [QuestionType::Numeric, ['answer' => 3.14, 'tolerance' => 0.01]],
        [QuestionType::ShortAnswer, ['accept' => ['photosynthesis']]],
        [QuestionType::FillBlank, ['blanks' => [['id' => 'b1', 'accept' => ['ran']]]]],
        [QuestionType::Ordering, ['correct_order' => ['a', 'b']]],
    ] as [$type, $grading]) {
        $question = Question::factory()->ofType($type, $grading)->create();
        [$attempt] = attemptFor($question);

        $payload = QuestionViewerResource::forAttempt($question->fresh(), $attempt, 10.0);
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        expect(keysDeep($payload))->not->toContain('grading', "[{$type->value}] leaked its grading key");

        // Belt and braces: the answer key's *values* must not appear either.
        expect($json)->not->toContain('photosynthesis')
            ->and($json)->not->toContain('3.14');
    }
});

it('never emits match_key, which is the answer key for a matching question', function () {
    // The match keys are deliberately unlike the visible text: a key of "past"
    // beside an option reading "Simple past" would make this assertion pass for
    // the wrong reason.
    $question = Question::factory()->ofType(QuestionType::Match)
        ->withOptions([
            ['text' => 'Ran', 'match_key' => 'PAIRKEY1'],
            ['text' => 'Simple past', 'match_key' => 'PAIRKEY1'],
            ['text' => 'Runs', 'match_key' => 'PAIRKEY2'],
            ['text' => 'Simple present', 'match_key' => 'PAIRKEY2'],
        ])
        ->create();

    [$attempt] = attemptFor($question);
    $json = json_encode(QuestionViewerResource::forAttempt($question->fresh(), $attempt, 10.0), JSON_THROW_ON_ERROR);

    expect($json)->not->toContain('match_key')
        ->and($json)->not->toContain('PAIRKEY')
        // The options themselves must still be there, or the learner has nothing
        // to match.
        ->and($json)->toContain('Simple past');
});

it('shuffles options per attempt but stably within one', function () {
    $question = Question::factory()->ofType(QuestionType::McqSingle)
        ->withOptions(array_map(fn ($i) => ['text' => "option {$i}"], range(1, 8)))
        ->create();

    [$attempt] = attemptFor($question, ['shuffle_options' => true]);

    $first = QuestionViewerResource::forAttempt($question->fresh(), $attempt, 10.0)['options'];
    $second = QuestionViewerResource::forAttempt($question->fresh(), $attempt->fresh(), 10.0)['options'];

    // Same attempt, same order — an option order that moves under the learner's
    // finger between two renders is worse than no shuffle at all.
    expect(array_column($first, 'id'))->toBe(array_column($second, 'id'));

    $otherLearner = User::factory()->create();
    $otherAttempt = app(StartAttempt::class)->handle(
        Assessment::find($attempt->assessment_id), $otherLearner,
    );
    $theirs = QuestionViewerResource::forAttempt($question->fresh(), $otherAttempt, 10.0)['options'];

    expect(array_column($theirs, 'id'))->not->toBe(array_column($first, 'id'))
        ->and(array_column($theirs, 'id'))->toEqualCanonicalizing(array_column($first, 'id'));
});

it('preserves authored option order when shuffling is off', function () {
    $question = Question::factory()->ofType(QuestionType::McqSingle)
        ->withOptions([['text' => 'a'], ['text' => 'b'], ['text' => 'c']])
        ->create();

    [$attempt] = attemptFor($question, ['shuffle_options' => false]);

    $options = QuestionViewerResource::forAttempt($question->fresh(), $attempt, 10.0)['options'];

    expect(array_column(array_column($options, 'body'), 'text'))->toBe(['a', 'b', 'c']);
});

/*
|--------------------------------------------------------------------------
| After grading, on the assessment's terms
|--------------------------------------------------------------------------
*/

it('reveals the answer key only through the explicit resource', function () {
    $question = Question::factory()->ofType(QuestionType::McqSingle)
        ->withOptions([['text' => 'ran', 'correct' => true], ['text' => 'run']])
        ->create();

    $payload = QuestionViewerResource::withAnswerKey($question->fresh(), 10.0);

    expect(keysDeep($payload))->toContain('is_correct')->toContain('explanation');
});

it('withholds answers from a failing learner when show_answers is after_pass', function () {
    $settings = AssessmentSettings::for(Assessment::KIND_TEST);

    expect($settings->showAnswers)->toBe(AssessmentSettings::SHOW_AFTER_PASS)
        ->and($settings->mayRevealAnswers(passed: false))->toBeFalse()
        ->and($settings->mayRevealAnswers(passed: true))->toBeTrue();
});

it('shows answers after submission on a formative quiz', function () {
    $settings = AssessmentSettings::for(Assessment::KIND_QUIZ);

    expect($settings->mayRevealAnswers(passed: false))->toBeTrue();
});

it('never shows answers when the assessment says never', function () {
    $settings = AssessmentSettings::for(Assessment::KIND_TEST, ['show_answers' => 'never']);

    expect($settings->mayRevealAnswers(passed: true))->toBeFalse();
});
