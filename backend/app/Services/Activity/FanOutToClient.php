<?php

namespace App\Services\Activity;

use App\Models\ActivityEvent;
use App\Models\ClientEntitlement;
use App\Models\ClientEventOutbox;
use App\Models\Course;
use Illuminate\Support\Facades\DB;

/**
 * Assigns each client-attributed event its place in that client's gapless,
 * monotonic sequence.
 *
 * A Postgres sequence is monotonic but *not* gapless — a rolled-back
 * transaction burns a number, and a gap is indistinguishable from a lost event.
 * Gaplessness is the single property that lets a SIS reconcile: they notice the
 * sequence jumped and ask for what they missed.
 */
final class FanOutToClient
{
    public function queue(string $clientId, ActivityEvent $event): ClientEventOutbox
    {
        // Row-level lock, inside the caller's transaction: two concurrent
        // ingests for one client serialise here rather than racing on max().
        $sequence = (int) DB::selectOne(<<<'SQL'
            INSERT INTO client_outbox_state (client_id, next_sequence, created_at, updated_at)
            VALUES (?, 2, now(), now())
            ON CONFLICT (client_id) DO UPDATE
                SET next_sequence = client_outbox_state.next_sequence + 1,
                    updated_at = now()
            RETURNING next_sequence - 1 AS sequence
        SQL, [$clientId])->sequence;

        return ClientEventOutbox::create([
            'client_id' => $clientId,
            'sequence' => $sequence,
            'event_id' => $event->id,
            'event_occurred_at' => $event->occurred_at,
            'next_attempt_at' => now(),
        ]);
    }

    /**
     * Overage is recorded on the event, never enforced against it.
     *
     * A student locked out because their school under-purchased is a support
     * escalation, and the school pays anyway. Flag it; invoice.
     */
    public function isOverSeat(?string $clientId, Course $course): bool
    {
        if ($clientId === null) {
            return false;
        }

        $productIds = DB::table('product_courses')->where('course_id', $course->id)->pluck('product_id');

        return ClientEntitlement::where('client_id', $clientId)
            ->whereIn('product_id', $productIds)
            ->live()
            ->get()
            ->contains(fn (ClientEntitlement $e) => $e->isOverSeats());
    }
}
