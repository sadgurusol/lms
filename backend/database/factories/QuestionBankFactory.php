<?php

namespace Database\Factories;

use App\Models\QuestionBank;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<QuestionBank> */
class QuestionBankFactory extends Factory
{
    protected $model = QuestionBank::class;

    public function definition(): array
    {
        return ['name' => 'Bank '.fake()->unique()->word(), 'course_id' => null];
    }
}
