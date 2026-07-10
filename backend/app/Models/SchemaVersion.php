<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\SchemaVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An immutable-once-published snapshot of a schema's structure.
 *
 * Courses bind here, not to CourseSchema. "Editing" a published version clones
 * it into a new draft; the published rows are never touched. The database
 * enforces this with triggers — see the M1 migration.
 *
 * @property string $id
 * @property string $course_schema_id
 * @property int $version
 * @property string $status
 * @property string|null $notes
 * @property Carbon|null $published_at
 * @property string|null $published_by
 * @property-read Collection<int, SchemaLevel> $levels
 * @property-read CourseSchema $courseSchema
 */
#[Fillable(['course_schema_id', 'version', 'status', 'notes', 'published_at', 'published_by'])]
class SchemaVersion extends Model
{
    /** @use HasFactory<SchemaVersionFactory> */
    use Auditable, HasFactory, HasUuids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    /** @return BelongsTo<CourseSchema, $this> */
    public function courseSchema(): BelongsTo
    {
        return $this->belongsTo(CourseSchema::class);
    }

    /** @return HasMany<SchemaLevel, $this> */
    public function levels(): HasMany
    {
        return $this->hasMany(SchemaLevel::class)->orderBy('depth')->orderBy('sort_key');
    }

    /** @return HasMany<SchemaLevel, $this> */
    public function rootLevels(): HasMany
    {
        return $this->levels()->whereNull('parent_level_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }
}
