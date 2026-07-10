<?php

namespace App\Services\Assessments;

use App\Assessments\AssessmentSettings;
use App\Assessments\SeededShuffle;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class StartAttempt
{
    public function handle(Assessment $assessment, User $learner): AssessmentAttempt
    {
        $settings = $assessment->config();

        return DB::transaction(function () use ($assessment, $learner, $settings) {
            $course = $assessment->course()->lockForUpdate()->firstOrFail();

            if ($course->latest_publication_id === null) {
                throw new RuntimeException('This course has not been published yet.');
            }

            $previous = AssessmentAttempt::where('assessment_id', $assessment->id)
                ->where('user_id', $learner->id)
                ->get();

            if ($resumable = $previous->firstWhere('state', AssessmentAttempt::IN_PROGRESS)) {
                return $resumable;   // resuming, not starting: idempotent by design
            }

            // Expired attempts still count: a learner who ran out the clock has
            // used an attempt.
            if ($settings->maxAttempts !== null && $previous->count() >= $settings->maxAttempts) {
                throw new RuntimeException(
                    "You have used all {$settings->maxAttempts} attempt(s) at this assessment."
                );
            }

            $questionIds = $this->resolveQuestionSet($assessment, $id = (string) Str::uuid7(), $settings);

            if ($questionIds === []) {
                throw new RuntimeException('This assessment has no questions.');
            }

            // forceFill, not create(): the id is generated up front because it
            // seeds the question and option shuffles, and it is not fillable.
            $attempt = new AssessmentAttempt;
            $attempt->forceFill([
                'id' => $id,
                'assessment_id' => $assessment->id,
                'publication_id' => $course->latest_publication_id,
                'user_id' => $learner->id,
                'attempt_number' => $previous->max('attempt_number') + 1,
                'state' => AssessmentAttempt::IN_PROGRESS,
                'question_order' => $questionIds,
                'started_at' => now(),
                'expires_at' => $settings->timeLimitSeconds === null
                    ? null
                    : now()->addSeconds($settings->timeLimitSeconds),
            ])->save();

            return $attempt->refresh();
        });
    }

    /**
     * Pool, then shuffle, then freeze — seeded by the attempt id.
     *
     * The id is generated before the row so the same seed drives both the
     * question order and, later, each question's option order. Freezing the
     * result is what makes an attempt resumable and auditable.
     *
     * @return list<string>
     */
    private function resolveQuestionSet(Assessment $assessment, string $attemptId, AssessmentSettings $settings): array
    {
        /** @var list<string> $ordered */
        $ordered = $assessment->assessmentQuestions()->pluck('id')->all();   // by sort_key
        $ids = $ordered;

        if ($settings->questionPoolSize !== null) {
            if ($settings->questionPoolSize > count($ids)) {
                throw new RuntimeException(
                    "This assessment draws {$settings->questionPoolSize} questions from a pool of only "
                        .count($ids).'.'
                );
            }

            $drawn = array_slice(SeededShuffle::apply($ids, "pool:{$attemptId}"), 0, $settings->questionPoolSize);

            // Drawing a subset must not silently reorder it: an assessment with
            // pooling but shuffle_questions off still presents its questions in
            // the order the author arranged them.
            $ids = array_values(array_filter($ordered, fn (string $id) => in_array($id, $drawn, true)));
        }

        return $settings->shuffleQuestions
            ? SeededShuffle::apply($ids, "order:{$attemptId}")
            : $ids;
    }
}
