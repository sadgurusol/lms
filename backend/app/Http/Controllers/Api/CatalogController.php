<?php

namespace App\Http\Controllers\Api;

use App\Entitlements\EntitlementResolver;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Plan;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The B2C storefront: products a learner can buy, each with the courses it
 * unlocks and its price options. Purchase happens through
 * {@see SubscriptionController} / {@see PurchaseController}, which open a
 * checkout; access arrives with the payment webhook.
 */
class CatalogController extends Controller
{
    public function __construct(private readonly EntitlementResolver $resolver) {}

    public function index(Request $request): JsonResponse
    {
        $owned = $this->resolver->entitledProductIds($request->user(), $request->user()->currentClientId());

        $products = Product::query()
            ->active()
            ->whereIn('kind', [Product::KIND_COURSE, Product::KIND_BUNDLE, Product::KIND_CATALOG])
            // Only what a learner can actually buy: a product with a live plan.
            ->whereHas('plans', fn ($q) => $q->where('status', 'active'))
            ->with([
                'plans' => fn ($q) => $q->where('status', 'active')->orderBy('price_minor'),
                'courses' => fn ($q) => $q->whereNotNull('latest_publication_id')->orderBy('title'),
            ])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $products->map(function (Product $product) use ($owned) {
                /** @var array<string, mixed> $row */
                $row = [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'kind' => $product->kind,
                    'owned' => in_array($product->id, $owned, true),
                    'courses' => $product->courses->map(fn (Course $c) => [
                        'id' => $c->id,
                        'title' => $c->title,
                        'subject' => $c->subject,
                        'code' => $c->code,
                    ])->all(),
                    'plans' => $product->plans->map(fn (Plan $plan) => [
                        'code' => $plan->code,
                        'name' => $plan->name,
                        'price_minor' => $plan->price_minor,
                        'currency' => $plan->currency,
                        'interval' => $plan->interval,
                        'trial_days' => $plan->trial_days,
                        'is_subscription' => $plan->interval !== Plan::ONE_TIME,
                    ])->all(),
                ];

                return $row;
            })->all(),
        ]);
    }
}
