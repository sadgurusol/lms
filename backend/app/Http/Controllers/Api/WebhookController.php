<?php

namespace App\Http\Controllers\Api;

use App\Billing\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Verify, persist, acknowledge, process.
 *
 * Providers time out at 5–10 seconds and retry. A slow entitlement rebuild
 * inline would be read as a delivery failure, so the handler does the minimum
 * synchronously and queues the rest.
 */
class WebhookController extends Controller
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    public function razorpay(Request $request): JsonResponse
    {
        // getContent() is the bytes as received. Re-encoding $request->all()
        // reorders keys and drops whitespace, and the HMAC will never match.
        $rawBody = $request->getContent();
        $signature = (string) $request->header('X-Razorpay-Signature', '');

        if (! $this->gateway->verifySignature($rawBody, $signature)) {
            return response()->json(['error' => 'invalid signature'], 401);
        }

        $body = $request->json()->all();
        $data = $this->gateway->parseEvent($body);
        $eventId = $request->header('X-Razorpay-Event-Id') ?? $data->eventId;

        $id = (string) Str::uuid7();

        // insertOrIgnore, not a try/catch on the unique violation: a failed
        // INSERT aborts the surrounding Postgres transaction, so catching it
        // leaves the connection unusable for everything after. ON CONFLICT DO
        // NOTHING is the idempotency guard and the race guard in one statement.
        $inserted = DB::table('webhook_events')->insertOrIgnore([
            'id' => $id,
            'provider' => $this->gateway->name(),
            'provider_event_id' => $eventId,
            'type' => $data->type,
            'payload' => json_encode($body, JSON_THROW_ON_ERROR),
            'occurred_at' => $data->occurredAt,
            'received_at' => now(),
        ]);

        if ($inserted === 0) {
            // A redelivery. Already stored, already queued, possibly already
            // applied. Acknowledge and do nothing.
            return response()->json(['status' => 'duplicate'], 200);
        }

        ProcessWebhookEvent::dispatch($id);

        return response()->json(['status' => 'accepted'], 202);
    }
}
