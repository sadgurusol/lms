<?php

namespace Database\Factories;

use App\Models\SchemaLevel;
use App\Models\SchemaVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SchemaLevel>
 */
class SchemaLevelFactory extends Factory
{
    protected $model = SchemaLevel::class;

    public function definition(): array
    {
        $name = Str::title(fake()->word());

        return [
            'schema_version_id' => SchemaVersion::factory(),
            'parent_level_id' => null,
            'name' => $name,
            'plural_name' => Str::plural($name),
            'depth' => 0,
            // FractionalIndex::between(null, null). A key must never end in '0'
            // — see the sort_key CHECK constraint.
            'sort_key' => 'V',
            'min_occurrences' => 0,
            'max_occurrences' => null,
            'allows_content' => false,
            'allowed_block_types' => [],
            'allows_assessment' => false,
            'numbering_style' => 'numeric',
            'label_template' => '{title}',
        ];
    }

    public function under(SchemaLevel $parent, string $sortKey = 'V'): static
    {
        return $this->state(fn () => [
            'schema_version_id' => $parent->schema_version_id,
            'parent_level_id' => $parent->id,
            'depth' => $parent->depth + 1,
            'sort_key' => $sortKey,
        ]);
    }

    public function withContent(array $blockTypes = ['rich_text']): static
    {
        return $this->state(fn () => [
            'allows_content' => true,
            'allowed_block_types' => $blockTypes,
        ]);
    }
}
