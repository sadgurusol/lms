<?php

namespace App\Tutor;

use App\Models\TutorMessage;
use App\Models\User;

/**
 * A per-learner monthly cap on tutor token spend — a runaway-cost brake.
 *
 * Tokens are already recorded on every assistant message, so usage is a sum,
 * not a separate counter to keep in step. See docs/12-ai-tutor.md.
 */
final class TutorBudget
{
    /** The configured cap, or null when usage is unlimited. */
    public function budget(): ?int
    {
        $budget = config('tutor.monthly_token_budget');

        return $budget === null ? null : (int) $budget;
    }

    public function usedThisMonth(User $user): int
    {
        return (int) TutorMessage::query()
            ->whereHas('conversation', fn ($q) => $q->where('user_id', $user->id))
            ->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('COALESCE(SUM(COALESCE(input_tokens, 0) + COALESCE(output_tokens, 0)), 0) AS total')
            ->value('total');
    }

    /** Tokens left this month, or null when unlimited. */
    public function remaining(User $user): ?int
    {
        $budget = $this->budget();

        return $budget === null ? null : max(0, $budget - $this->usedThisMonth($user));
    }

    public function exceeded(User $user): bool
    {
        $remaining = $this->remaining($user);

        return $remaining !== null && $remaining <= 0;
    }
}
