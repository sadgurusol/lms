<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureClientScope;
use App\Models\ActivityEvent;
use App\Models\Client;
use App\Models\ClientEntitlement;
use App\Models\ClientEventOutbox;
use App\Models\ClientOutboxState;
use App\Models\ClientUser;
use App\Services\Activity\ActivitySerialiser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The client's own console. Three screens, and it removes most of the B2B
 * support load: seats, roster, integration health.
 *
 * The client comes from EnsureClientScope, which read it off the access token.
 * Reading it from a query parameter would be the whole bug.
 */
class ClientConsoleController extends Controller
{
    public function roster(Request $request): JsonResponse
    {
        $client = EnsureClientScope::clientFor($request);
        $this->assertClientAdmin($request, $client);

        $members = ClientUser::where('client_id', $client->id)->get();

        return response()->json([
            'data' => $members->map(fn (ClientUser $member) => [
                'external_user_id' => $member->external_user_id,
                'name' => $member->external_name,
                'role' => $member->role,
                'status' => $member->status,
                'last_seen_at' => $member->last_seen_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function seats(Request $request): JsonResponse
    {
        $client = EnsureClientScope::clientFor($request);
        $this->assertClientAdmin($request, $client);

        $entitlements = ClientEntitlement::where('client_id', $client->id)->live()->get();

        return response()->json([
            'data' => $entitlements->map(fn (ClientEntitlement $e) => [
                'product_id' => $e->product_id,
                'seat_model' => $e->seat_model,
                'seat_limit' => $e->seat_limit,
                'seats_used' => $e->seatsUsed(),
                // Surfaced, never enforced by locking a learner out mid-term.
                'over_seats' => $e->isOverSeats(),
                'ends_at' => $e->ends_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * Pull reporting, for the many SIS that sit behind a firewall and cannot
     * receive a webhook.
     *
     * The cursor is `sequence`, not a timestamp. Timestamps collide, go
     * backwards under clock skew, and make "did I already fetch this?"
     * unanswerable.
     */
    public function activity(Request $request, ActivitySerialiser $serialiser): JsonResponse
    {
        $client = EnsureClientScope::clientFor($request);
        $this->assertClientAdmin($request, $client);

        $data = $request->validate([
            'since_sequence' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        $limit = $data['limit'] ?? 200;

        $rows = ClientEventOutbox::query()
            ->where('client_id', $client->id)
            ->where('sequence', '>', $data['since_sequence'] ?? 0)
            ->orderBy('sequence')
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        // Filtered by client_id twice on purpose: once through the outbox, once
        // on the events themselves. A query that does not filter on client_id is
        // a query that one day will not be per-client.
        $events = ActivityEvent::whereIn('id', $rows->pluck('event_id'))
            ->where('client_id', $client->id)
            ->get()
            ->keyBy('id');

        $data = $rows->map(fn (ClientEventOutbox $row) => [
            'sequence' => $row->sequence,
            ...$serialiser->forClient($client, $events[$row->event_id]),
        ])->values();

        return response()->json([
            'data' => $data->all(),
            'meta' => [
                'last_sequence' => $rows->last()?->sequence,
                'has_more' => $hasMore,
            ],
        ]);
    }

    /**
     * Integration health. "The launch doesn't work" and "we're missing events"
     * are 80% of B2B support; this is where the answer lives.
     */
    public function delivery(Request $request): JsonResponse
    {
        $client = EnsureClientScope::clientFor($request);
        $this->assertClientAdmin($request, $client);

        $state = ClientOutboxState::find($client->id);
        $pending = ClientEventOutbox::where('client_id', $client->id)->whereNull('delivered_at');

        return response()->json([
            'webhook_url' => $client->report_webhook_url,
            'parked' => $state?->isParked() ?? false,
            'parked_reason' => $state?->parked_reason,
            'next_sequence' => $state === null ? 1 : $state->next_sequence,
            'pending' => (clone $pending)->count(),
            'oldest_pending_sequence' => (clone $pending)->min('sequence'),
            'last_error' => (clone $pending)->orderByDesc('attempts')->value('last_error'),
        ]);
    }

    private function assertClientAdmin(Request $request, Client $client): void
    {
        $membership = ClientUser::where('client_id', $client->id)
            ->where('user_id', $request->user()->id)
            ->first();

        abort_unless(
            $membership?->role === ClientUser::CLIENT_ADMIN,
            403,
            'The client console is for client administrators.',
        );
    }
}
