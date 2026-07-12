<?php

namespace App\Services\Activity;

use App\Models\Client;
use App\Models\ClientEventOutbox;
use App\Models\ClientOutboxState;
use Illuminate\Support\Facades\DB;

/**
 * The client fixed their endpoint and needs the gap.
 *
 * At-least-once delivery means their handler is idempotent on `event_id` — so
 * re-sending events they already have is safe, and re-sending events they never
 * got is the whole point.
 */
final class ReplayOutbox
{
    public function handle(Client $client, int $fromSequence): int
    {
        return DB::transaction(function () use ($client, $fromSequence) {
            $count = ClientEventOutbox::where('client_id', $client->id)
                ->where('sequence', '>=', $fromSequence)
                ->update([
                    'delivered_at' => null,
                    'attempts' => 0,
                    'last_error' => null,
                    'next_attempt_at' => now(),
                ]);

            ClientOutboxState::where('client_id', $client->id)
                ->update(['parked_at' => null, 'parked_reason' => null]);

            return $count;
        });
    }
}
