<?php

namespace Database\Factories;

use App\Models\CourseSchema;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CourseSchema>
 */
class CourseSchemaFactory extends Factory
{
    protected $model = CourseSchema::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'description' => fake()->sentence(),
        ];
    }
}
