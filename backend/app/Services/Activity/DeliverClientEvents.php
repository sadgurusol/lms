<?php

namespace App\Services\Activity;

use App\Models\ActivityEvent;
use App\Models\Client;
use App\Models\ClientEventOutbox;
use App\Models\ClientOutboxState;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one client's pending events, in sequence order, at least once.
 *
 * Three rules, and the third is the one people break:
 *
 *  1. **In order.** One in-flight batch per client. `sequence` is the contract.
 *  2. **At least once.** The client's handler is idempotent on `event_id`, and
 *     the partner docs say so loudly.
 *  3. **Never skip a failing batch.** A gap is indistinguishable from a lost
 *     event, and gaplessness is the only thing that makes reconciliation
 *     possible. A stream that cannot be delivered is *parked* and alerted on,
 *     never stepped over.
 */
final class DeliverClientEvents
{
    public const BATCH_SIZE = 100;

    /** Seconds. After the last rung the stream parks. */
    public const RETRY_LADDER = [60, 300, 900, 3600, 21600, 86400];

    public function __construct(private readonly ActivitySerialiser $serialiser) {}

    /** @return int events delivered */
    public function handle(Client $client): int
    {
        $state = ClientOutboxState::find($client->id);

        if ($state === null || $state->isParked() || $client->report_webhook_url === null) {
            return 0;
        }

        // Head-of-line blocking is the point.
        //
        // The backoff belongs to the *stream*, not to each row. Filtering
        // `next_attempt_at <= now()` per row would let a freshly queued event
        // overtake a backed-off earlier one, and the client would receive
        // sequence 2 before sequence 1 — a gap they cannot distinguish from a
        // lost event. Gaplessness is the whole contract.
        $head = ClientEventOutbox::query()
            ->where('client_id', $client->id)
            ->whereNull('delivered_at')
            ->orderBy('sequence')
            ->first();

        if ($head === null || ($head->next_attempt_at !== null && $head->next_attempt_at->isFuture())) {
            return 0;
        }

        $pending = ClientEventOutbox::query()
            ->where('client_id', $client->id)
            ->whereNull('delivered_at')
            ->where('sequence', '>=', $head->sequence)
            ->orderBy('sequence')
            ->limit(self::BATCH_SIZE)
            ->get();

        $events = $this->loadEvents($client, $pending);

        try {
            $this->post($client, $events, $pending->first()->sequence, $pending->last()->sequence);
        } catch (Throwable $e) {
            $this->recordFailure($client, $state, $pending, $e->getMessage());

            return 0;
        }

        ClientEventOutbox::where('client_id', $client->id)
            ->whereIn('sequence', $pending->pluck('sequence'))
            ->update(['delivered_at' => now(), 'last_error' => null]);

        return $pending->count();
    }

    /**
     * @param  Collection<int, ClientEventOutbox>  $pending
     * @return list<array<string, mixed>>
     */
    private function loadEvents(Client $client, $pending): array
    {
        $events = ActivityEvent::query()
            ->whereIn('id', $pending->pluck('event_id'))
            // Belt and braces. The outbox is per-client already, but a query
            // that does not filter on client_id is a query that one day will
            // not be per-client.
            ->where('client_id', $client->id)
            ->get()
            ->keyBy('id');

        return $pending
            ->map(fn (ClientEventOutbox $row) => $events->get($row->event_id))
            ->filter()
            ->map(fn (ActivityEvent $event) => $this->serialiser->forClient($client, $event))
            ->values()
            ->all();
    }

    /**
     * Stripe-style signature: `t=<unix>,v1=<hmac>` over `"{t}.{raw_body}"`.
     *
     * The timestamp is inside the signed material, so a captured request cannot
     * be replayed at leisure — the client rejects anything older than its
     * tolerance. Publish the verification snippet in the partner docs; nobody
     * implements it correctly from prose.
     *
     * @param  list<array<string, mixed>>  $events
     */
    private function post(Client $client, array $events, int $from, int $to): void
    {
        $body = json_encode([
            'events' => $events,
            'sequence_range' => ['from' => $from, 'to' => $to],
        ], JSON_THROW_ON_ERROR);

        $timestamp = now()->timestamp;
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", (string) $client->webhook_secret);

        Http::timeout(10)
            ->withBody($body, 'application/json')
            ->withHeaders([
                'X-LMS-Signature' => "t={$timestamp},v1={$signature}",
                'X-LMS-Sequence-Range' => "{$from}-{$to}",
            ])
            ->post($client->report_webhook_url)
            ->throw();
    }

    /** @param Collection<int, ClientEventOutbox> $pending */
    private function recordFailure(Client $client, ClientOutboxState $state, $pending, string $error): void
    {
        $attempts = $pending->first()->attempts + 1;
        $rung = self::RETRY_LADDER[$attempts - 1] ?? null;

        ClientEventOutbox::where('client_id', $client->id)
            ->whereIn('sequence', $pending->pluck('sequence'))
            ->update([
                'attempts' => $attempts,
                'last_error' => mb_substr($error, 0, 1000),
                'next_attempt_at' => $rung === null ? null : now()->addSeconds($rung),
            ]);

        if ($rung !== null) {
            return;
        }

        // Exhausted. Park the stream rather than skipping the batch: the client
        // resumes it from the console once they have fixed their endpoint.
        $state->forceFill([
            'parked_at' => now(),
            'parked_reason' => mb_substr($error, 0, 1000),
        ])->save();

        Log::error('Client event stream parked', [
            'client_id' => $client->id,
            'sequence' => $pending->first()->sequence,
            'error' => $error,
        ]);
    }
}
