<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PaymentsUnavailable;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\Billing\StartPurchase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PurchaseController extends Controller
{
    /** Opens a payment link. Access arrives with the capture webhook, not here. */
    public function store(Request $request, StartPurchase $starter): JsonResponse
    {
        $data = $request->validate(['plan_code' => ['required', 'string']]);

        $plan = Plan::where('code', $data['plan_code'])->firstOrFail();

        try {
            return response()->json($starter->handle($request->user(), $plan), 201);
        } catch (PaymentsUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
