<?php

namespace App\Services\Assessments;

use App\Models\AssessmentAttempt;

/**
 * Runs every minute.
 *
 * An expired attempt is submitted and graded, never discarded. Learners who
 * lose connectivity mid-test should not lose their work; `meta.auto_submitted`
 * tells a teacher why the attempt ended.
 */
final class ExpireAttempts
{
    private const GRACE_SECONDS = 60;

    public function __construct(private readonly SubmitAttempt $submissions) {}

    public function handle(): int
    {
        $expired = AssessmentAttempt::query()
            ->where('state', AssessmentAttempt::IN_PROGRESS)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now()->subSeconds(self::GRACE_SECONDS))
            ->get();

        foreach ($expired as $attempt) {
            $this->submissions->handle($attempt, autoSubmitted: true);
        }

        return $expired->count();
    }
}
