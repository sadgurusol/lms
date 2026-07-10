<?php

namespace Database\Factories;

use App\Models\Assessment;
use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Assessment> */
class AssessmentFactory extends Factory
{
    protected $model = Assessment::class;

    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'course_node_id' => null,
            'kind' => Assessment::KIND_QUIZ,
            'title' => 'Check your understanding',
            'settings' => [],
        ];
    }

    public function test(array $settings = []): static
    {
        return $this->state(fn () => ['kind' => Assessment::KIND_TEST, 'settings' => $settings]);
    }

    public function quiz(array $settings = []): static
    {
        return $this->state(fn () => ['kind' => Assessment::KIND_QUIZ, 'settings' => $settings]);
    }
}
