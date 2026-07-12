<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PaymentsUnavailable;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\StartSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class SubscriptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $subscriptions = Subscription::with('plan:id,code,name,price_minor,currency,interval')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json([
            'data' => $subscriptions->map(fn (Subscription $s) => [
                'id' => $s->id,
                'status' => $s->status,
                'entitles' => $s->isEntitling(),
                'current_period_end' => $s->current_period_end?->toIso8601String(),
                'canceled_at' => $s->canceled_at?->toIso8601String(),
                'plan' => [
                    'code' => $s->plan->code,
                    'name' => $s->plan->name,
                    'price_minor' => $s->plan->price_minor,
                    'currency' => $s->plan->currency,
                    'interval' => $s->plan->interval,
                ],
            ])->all(),
        ]);
    }

    /** Opens a checkout. Access arrives with the activation webhook, not here. */
    public function store(Request $request, StartSubscription $starter): JsonResponse
    {
        $data = $request->validate(['plan_code' => ['required', 'string']]);

        $plan = Plan::where('code', $data['plan_code'])->firstOrFail();

        try {
            $result = $starter->handle($request->user(), $plan);
        } catch (PaymentsUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'subscription_id' => $result['subscription']->id,
            'status' => $result['subscription']->status,
            'checkout_url' => $result['checkout_url'],
        ], 201);
    }
}
