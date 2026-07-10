<?php

namespace App\Http\Resources;

use App\Assessments\SeededShuffle;
use App\Models\AssessmentAttempt;
use App\Models\Question;
use App\Models\QuestionOption;

/**
 * A question as a learner may see it, mid-attempt.
 *
 * Strips `is_correct`, `grading`, `explanation` and `feedback`. Invariant I14
 * lives or dies here. There is a test asserting no key named `is_correct`
 * appears at *any* depth of this output — it exists to catch the regression
 * someone introduces in month nine by adding `->load('options')` somewhere.
 *
 * Not an Eloquent JsonResource: those serialise the model by default, so a new
 * column leaks unless someone remembers to exclude it. This builds the payload
 * from nothing and adds only what a learner is allowed to have.
 */
final class QuestionViewerResource
{
    /** @return array<string, mixed> */
    public static function forAttempt(Question $question, AssessmentAttempt $attempt, float $points): array
    {
        $shuffleOptions = $attempt->assessment->config()->shuffleOptions;

        $options = $question->options
            ->map(fn (QuestionOption $option) => [
                'id' => $option->id,
                'body' => $option->body,
            ])
            ->values()
            ->all();

        if ($shuffleOptions) {
            // Seeded by attempt AND question, so the order survives an app
            // restart and differs between questions.
            $options = SeededShuffle::apply($options, "options:{$attempt->id}:{$question->id}");
        }

        return [
            'id' => $question->id,
            'type' => $question->type,
            'stem' => $question->stem,
            'points' => $points,
            'media_id' => $question->media_id,
            'options' => $options,
        ];
    }

    /**
     * After grading, and only when the assessment's `show_answers` setting
     * permits it, the learner may see what was right and why.
     *
     * @return array<string, mixed>
     */
    public static function withAnswerKey(Question $question, float $points): array
    {
        return [
            'id' => $question->id,
            'type' => $question->type,
            'stem' => $question->stem,
            'points' => $points,
            'explanation' => $question->explanation,
            'options' => $question->options
                ->map(fn (QuestionOption $option) => [
                    'id' => $option->id,
                    'body' => $option->body,
                    'is_correct' => $option->is_correct,
                    'feedback' => $option->feedback,
                ])
                ->values()
                ->all(),
        ];
    }
}
