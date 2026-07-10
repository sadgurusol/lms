<?php

use App\Assessments\QuestionType;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentQuestion;
use App\Models\Question;
use App\Models\User;
use App\Services\Assessments\RecordAnswer;
use App\Services\Assessments\StartAttempt;
use App\Services\Assessments\SubmitAttempt;
use App\Support\FractionalIndex;

beforeEach(function () {
    [$this->course] = publishedTextbookCourse();
    $this->learner = User::factory()->create();
});

/**
 * The M5 acceptance criterion, verbatim from docs/09-roadmap.md:
 *
 *   "a learner takes a timed test, loses connectivity at question 12,
 *    reconnects, and submits without losing an answer."
 */
it('survives a learner losing connectivity mid-test and flushing an offline queue', function () {
    $assessment = Assessment::factory()->test([
        'time_limit_s' => 1800,
        'shuffle_questions' => true,
        'pass_percentage' => 50,
    ])->create(['course_id' => $this->course->id]);

    $questions = [];
    for ($i = 0; $i < 20; $i++) {
        $q = Question::factory()->ofType(QuestionType::TrueFalse, ['answer' => $i % 2 === 0])->create();

        $questions[] = AssessmentQuestion::create([
            'assessment_id' => $assessment->id,
            'question_id' => $q->id,
            'points' => 1,
            'sort_key' => FractionalIndex::between($questions === [] ? null : end($questions)->sort_key, null),
        ]);
    }
    $assessment->syncTotalPoints();

    $attempt = app(StartAttempt::class)->handle($assessment->fresh(), $this->learner);
    $order = $attempt->question_order;
    $answerOf = fn (string $aqId) => AssessmentQuestion::find($aqId)->question->grading['answer'];

    // Questions 1..11 answered online, correctly.
    foreach (array_slice($order, 0, 11) as $aqId) {
        app(RecordAnswer::class)->handle($attempt, $aqId, ['answer' => $answerOf($aqId)]);
    }

    // Connectivity drops at question 12. The learner keeps answering; the client
    // queues each answer with the time it was written.
    $this->travel(5)->minutes();
    $offlineQueue = [];
    foreach (array_slice($order, 11, 5) as $aqId) {
        $offlineQueue[] = ['aq' => $aqId, 'response' => ['answer' => $answerOf($aqId)], 'at' => now()];
        $this->travel(30)->seconds();
    }

    // Reconnects. The outbox flushes, twice — replaying after a crash must be safe.
    foreach ([1, 2] as $_) {
        foreach ($offlineQueue as $queued) {
            app(RecordAnswer::class)->handle(
                $attempt->fresh(), $queued['aq'], $queued['response'], $queued['at'],
            );
        }
    }

    $attempt = app(SubmitAttempt::class)->handle($attempt->fresh());

    expect($attempt->state)->toBe(AssessmentAttempt::GRADED)
        // 16 answered correctly, 4 never reached and recorded as skipped zeros.
        ->and($attempt->answers()->count())->toBe(20)
        ->and((float) $attempt->score)->toBe(16.0)
        ->and((float) $attempt->max_score)->toBe(20.0)
        ->and($attempt->passed)->toBeTrue()
        ->and($attempt->meta['auto_submitted'])->toBeFalse();

    // The offline answers landed once each, timestamped when the learner wrote
    // them rather than when the network came back.
    foreach ($offlineQueue as $queued) {
        $answer = $attempt->answers()->where('assessment_question_id', $queued['aq'])->get();

        expect($answer)->toHaveCount(1)
            ->and($answer->first()->is_correct)->toBeTrue()
            ->and($answer->first()->answered_at->diffInMinutes(now(), absolute: true))->toBeGreaterThan(0);
    }
});

it('round-trips postgres text[] tags', function () {
    $question = Question::factory()->create(['tags' => ['grammar', 'tense, past', 'quoted"tag']]);

    expect($question->fresh()->tags)->toBe(['grammar', 'tense, past', 'quoted"tag']);
});

it('stores empty tags as an empty array, not a null', function () {
    expect(Question::factory()->create()->fresh()->tags)->toBe([]);
});

it('finds questions by tag through the gin index', function () {
    Question::factory()->create(['tags' => ['grammar', 'tense']]);
    Question::factory()->create(['tags' => ['vocabulary']]);

    $found = Question::whereRaw("tags @> ARRAY['grammar']::text[]")->get();

    expect($found)->toHaveCount(1)
        ->and($found->first()->tags)->toContain('grammar');
});
