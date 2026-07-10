<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'sku' => Str::upper(Str::random(8)),
            'name' => 'Product '.fake()->unique()->word(),
            'kind' => Product::KIND_COURSE,
            'status' => Product::STATUS_ACTIVE,
        ];
    }

    public function bundle(): static
    {
        return $this->state(fn () => ['kind' => Product::KIND_BUNDLE]);
    }

    public function retired(): static
    {
        return $this->state(fn () => ['status' => 'retired']);
    }
}
