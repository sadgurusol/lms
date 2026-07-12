<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Plan> */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'code' => 'PLAN-'.Str::upper(Str::random(6)),
            'name' => 'Monthly',
            'product_id' => Product::factory(),
            'price_minor' => 49900,      // ₹499.00
            'currency' => 'INR',
            'interval' => Plan::MONTHLY,
            'trial_days' => 0,
            'provider_ref' => 'plan_'.Str::random(14),
            'status' => 'active',
        ];
    }
}
