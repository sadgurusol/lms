<?php

namespace App\Services\Assessments;

use App\Models\AssessmentAttempt;
use App\Models\AttemptAnswer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A human grades one answer. When no answer is left ungraded, the attempt
 * finalises and the learner sees a score.
 */
final class GradeAnswer
{
    public function __construct(private readonly SubmitAttempt $submissions) {}

    public function handle(AttemptAnswer $answer, User $grader, float $points, ?string $note = null): AssessmentAttempt
    {
        return DB::transaction(function () use ($answer, $grader, $points, $note) {
            $attempt = AssessmentAttempt::lockForUpdate()->findOrFail($answer->attempt_id);

            if ($attempt->state !== AssessmentAttempt::AWAITING_REVIEW) {
                throw new RuntimeException("This attempt is {$attempt->state} and is not awaiting grading.");
            }

            $maxPoints = (float) $answer->assessmentQuestion->points;

            if ($points < 0 || $points > $maxPoints) {
                throw new RuntimeException("Award between 0 and {$maxPoints} points.");
            }

            $answer->forceFill([
                'points_awarded' => $points,
                'is_correct' => $points >= $maxPoints,
                'grader_id' => $grader->id,
                'grader_note' => $note,
            ])->save();

            return $attempt->answers()->whereNull('is_correct')->exists()
                ? $attempt->refresh()
                : $this->submissions->finalise($attempt);
        });
    }
}
