<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Subscription> */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'plan_id' => Plan::factory(),
            'provider' => 'razorpay',
            'provider_sub_id' => 'sub_'.Str::random(14),
            'status' => Subscription::ACTIVE,
            'current_period_start' => now()->subDays(3),
            'current_period_end' => now()->addMonth(),
        ];
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => Subscription::EXPIRED,
            'current_period_end' => now()->subDay(),
        ]);
    }
}
