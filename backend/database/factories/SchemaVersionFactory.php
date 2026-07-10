<?php

namespace Database\Factories;

use App\Models\CourseSchema;
use App\Models\SchemaVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchemaVersion>
 */
class SchemaVersionFactory extends Factory
{
    protected $model = SchemaVersion::class;

    public function definition(): array
    {
        return [
            'course_schema_id' => CourseSchema::factory(),
            'version' => 1,
            'status' => SchemaVersion::STATUS_DRAFT,
        ];
    }

    /**
     * There is no `published()` state on purpose.
     *
     * A version can only be published once its levels exist, and the level
     * trigger refuses to write levels into a published version. Build the draft,
     * add levels, then call PublishSchemaVersion — the same path production
     * takes.
     */
    public function forSchema(CourseSchema $schema, int $version = 1): static
    {
        return $this->state(fn () => [
            'course_schema_id' => $schema->id,
            'version' => $version,
        ]);
    }
}
