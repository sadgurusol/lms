<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\SchemaVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Course>
 */
class CourseFactory extends Factory
{
    protected $model = Course::class;

    public function definition(): array
    {
        return [
            'title' => 'Grade '.fake()->numberBetween(1, 12).' '.fake()->word(),
            'code' => Str::upper(Str::random(3)).'-'.fake()->unique()->numberBetween(100, 999),
            'subject' => fake()->word(),
            'grade_band' => 'Grade 10',
            'language' => 'en',
            'schema_version_id' => SchemaVersion::factory(),
            'workflow_state' => Course::STATE_DRAFT,
        ];
    }

    public function onSchema(SchemaVersion $version): static
    {
        return $this->state(fn () => ['schema_version_id' => $version->id]);
    }

    public function inState(string $workflowState): static
    {
        return $this->state(fn () => ['workflow_state' => $workflowState]);
    }
}
