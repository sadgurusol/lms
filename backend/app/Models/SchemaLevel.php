<?php

namespace App\Models;

use Database\Factories\SchemaLevelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One node type inside a schema version: "Part", "Chapter", "Topic".
 *
 * Levels form a tree, not a chain — two levels may share a parent_level_id,
 * letting a schema say "a Unit contains Lessons *or* standalone Topics".
 *
 * `allows_content` is per-level, not leaf-only: every real textbook has a
 * chapter introduction sitting above its topics.
 *
 * @property string $id
 * @property string $schema_version_id
 * @property string|null $parent_level_id
 * @property string $name
 * @property string $plural_name
 * @property int $depth
 * @property string $sort_key
 * @property int $min_occurrences
 * @property int|null $max_occurrences
 * @property bool $allows_content
 * @property list<string> $allowed_block_types
 * @property bool $allows_assessment
 * @property string $numbering_style
 * @property string $label_template
 */
#[Fillable([
    'schema_version_id', 'parent_level_id', 'name', 'plural_name', 'depth', 'sort_key',
    'min_occurrences', 'max_occurrences', 'allows_content', 'allowed_block_types',
    'allows_assessment', 'numbering_style', 'label_template',
])]
class SchemaLevel extends Model
{
    /** @use HasFactory<SchemaLevelFactory> */
    use HasFactory, HasUuids;

    public const NUMBERING_STYLES = ['none', 'numeric', 'roman', 'alpha'];

    protected function casts(): array
    {
        return [
            'allowed_block_types' => 'array',
            'allows_content' => 'boolean',
            'allows_assessment' => 'boolean',
        ];
    }

    /** @return BelongsTo<SchemaVersion, $this> */
    public function schemaVersion(): BelongsTo
    {
        return $this->belongsTo(SchemaVersion::class);
    }

    /** @return BelongsTo<SchemaLevel, $this> */
    public function parentLevel(): BelongsTo
    {
        return $this->belongsTo(SchemaLevel::class, 'parent_level_id');
    }

    /** @return HasMany<SchemaLevel, $this> */
    public function childLevels(): HasMany
    {
        return $this->hasMany(SchemaLevel::class, 'parent_level_id')->orderBy('sort_key');
    }

    public function isRoot(): bool
    {
        return $this->parent_level_id === null;
    }

    /** A level with no child levels is where content necessarily bottoms out. */
    public function isLeaf(): bool
    {
        return ! $this->childLevels()->exists();
    }

    public function permitsBlockType(string $type): bool
    {
        return $this->allows_content && in_array($type, $this->allowed_block_types ?? [], true);
    }
}
