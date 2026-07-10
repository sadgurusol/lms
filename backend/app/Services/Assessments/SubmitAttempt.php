<?php

namespace App\Services\Assessments;

use App\Assessments\Grading\GraderRegistry;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentQuestion;
use App\Models\AttemptAnswer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SubmitAttempt
{
    public function __construct(private readonly GraderRegistry $graders) {}

    /**
     * Grade everything that can be graded, then decide whether a human is needed.
     *
     * An expired attempt is submitted and graded, not discarded: a learner who
     * loses connectivity mid-test must not lose their work.
     */
    public function handle(AssessmentAttempt $attempt, bool $autoSubmitted = false): AssessmentAttempt
    {
        return DB::transaction(function () use ($attempt, $autoSubmitted) {
            $attempt = AssessmentAttempt::lockForUpdate()->findOrFail($attempt->id);

            if (! $attempt->isInProgress()) {
                throw new RuntimeException("This attempt is already {$attempt->state}.");
            }

            $attempt->forceFill([
                'state' => AssessmentAttempt::SUBMITTED,
                'submitted_at' => now(),
                'meta' => [...$attempt->meta, 'auto_submitted' => $autoSubmitted],
            ])->save();

            $this->recordSkipped($attempt);

            $needsHuman = false;

            foreach ($attempt->answers()->with('assessmentQuestion.question.options')->get() as $answer) {
                $assessmentQuestion = $answer->assessmentQuestion;
                $question = $assessmentQuestion->question;

                $result = $this->graders->for($question->questionType())->grade(
                    $question,
                    $answer->response ?? [],
                    (float) $assessmentQuestion->points,
                );

                $answer->forceFill([
                    'is_correct' => $result->isCorrect,
                    'points_awarded' => $result->points,
                ])->save();

                $needsHuman = $needsHuman || $result->needsHumanGrading();
            }

            return $needsHuman
                ? $this->awaitReview($attempt)
                : $this->finalise($attempt);
        });
    }

    /**
     * Unanswered questions score zero — recorded explicitly.
     *
     * Item analysis has to distinguish "shown and skipped" from "never shown",
     * and a missing row cannot tell you which.
     */
    private function recordSkipped(AssessmentAttempt $attempt): void
    {
        $answered = $attempt->answers()->pluck('assessment_question_id')->all();

        foreach (array_diff($attempt->question_order, $answered) as $skipped) {
            AttemptAnswer::create([
                'attempt_id' => $attempt->id,
                'assessment_question_id' => $skipped,
                'response' => [],
                'answered_at' => now(),
            ]);
        }
    }

    private function awaitReview(AssessmentAttempt $attempt): AssessmentAttempt
    {
        $attempt->forceFill(['state' => AssessmentAttempt::AWAITING_REVIEW])->save();

        return $attempt->refresh();
    }

    public function finalise(AssessmentAttempt $attempt): AssessmentAttempt
    {
        $settings = $attempt->assessment->config();

        $score = (float) $attempt->answers()->sum('points_awarded');
        $maxScore = (float) AssessmentQuestion::whereIn('id', $attempt->question_order)->sum('points');

        $percentage = $maxScore > 0 ? $score / $maxScore * 100 : 0.0;

        $attempt->forceFill([
            'state' => AssessmentAttempt::GRADED,
            'graded_at' => now(),
            'score' => $score,
            'max_score' => $maxScore,
            // A formative quiz has no pass mark, so `passed` stays null rather
            // than defaulting to false and looking like a failure.
            'passed' => $settings->isGraded() ? $percentage >= $settings->passPercentage : null,
        ])->save();

        return $attempt->refresh();
    }
}
