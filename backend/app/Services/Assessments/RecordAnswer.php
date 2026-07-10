<?php

namespace App\Services\Assessments;

use App\Models\AssessmentAttempt;
use App\Models\AttemptAnswer;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use RuntimeException;

final class RecordAnswer
{
    /** Offline clients flush a queue on reconnect; accept a little late. */
    private const OFFLINE_GRACE_SECONDS = 60;

    /**
     * Upsert one answer. Idempotent: replaying an outbox after a crash is safe.
     *
     * @param  array<string, mixed>  $response
     */
    public function handle(
        AssessmentAttempt $attempt,
        string $assessmentQuestionId,
        array $response,
        ?CarbonInterface $clientAnsweredAt = null,
    ): AttemptAnswer {
        if (! $attempt->isInProgress()) {
            throw new RuntimeException("This attempt is {$attempt->state} and no longer accepts answers.");
        }

        $index = array_search($assessmentQuestionId, $attempt->question_order, true);

        if ($index === false) {
            throw new RuntimeException('That question is not part of this attempt.');
        }

        $this->assertWithinTimeLimit($attempt);
        $this->assertBacktrackAllowed($attempt, (int) $index);

        $answer = AttemptAnswer::updateOrCreate(
            ['attempt_id' => $attempt->id, 'assessment_question_id' => $assessmentQuestionId],
            ['response' => $response, 'answered_at' => $this->answeredAt($clientAnsweredAt)],
        );

        if ($index > $attempt->max_index_reached) {
            $attempt->forceFill(['max_index_reached' => $index])->save();
        }

        return $answer;
    }

    private function assertWithinTimeLimit(AssessmentAttempt $attempt): void
    {
        if ($attempt->expires_at === null) {
            return;
        }

        // Server-authoritative, with a grace window so a learner whose phone
        // reconnects three seconds late does not lose the answer they wrote in
        // time. Timed tests should refuse offline entry outright — say so in the
        // UI rather than silently voiding an attempt.
        if (now()->diffInSeconds($attempt->expires_at, absolute: false) < -self::OFFLINE_GRACE_SECONDS) {
            throw new RuntimeException('The time limit for this attempt has passed.');
        }
    }

    private function assertBacktrackAllowed(AssessmentAttempt $attempt, int $index): void
    {
        if ($attempt->assessment->config()->allowBacktrack) {
            return;
        }

        if ($index < $attempt->max_index_reached) {
            throw new RuntimeException('You cannot return to an earlier question in this assessment.');
        }
    }

    private function answeredAt(?CarbonInterface $clientAnsweredAt): Carbon
    {
        if ($clientAnsweredAt === null) {
            return now();
        }

        // Phones have wrong clocks. Clamp rather than trust: an unclamped device
        // timestamp can write an answer into next year.
        $earliest = now()->subDays(30);
        $latest = now()->addMinutes(5);
        $claimed = Carbon::instance($clientAnsweredAt->toDateTime());

        return $claimed->lessThan($earliest) ? $earliest
            : ($claimed->greaterThan($latest) ? $latest : $claimed);
    }
}
