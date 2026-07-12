<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * One node in a course's content tree.
 *
 * `path` and `depth` are written by a database trigger from `parent_id`, and
 * are read-only here. Assigning them from PHP is always a bug.
 *
 * @property string $id
 * @property string $course_id
 * @property string|null $parent_id
 * @property string $schema_level_id
 * @property string $title
 * @property string $slug
 * @property string|null $summary
 * @property string $sort_key
 * @property int $depth
 * @property string $path
 * @property-read Course $course
 * @property-read SchemaLevel $schemaLevel
 */
#[Fillable(['course_id', 'parent_id', 'schema_level_id', 'title', 'slug', 'summary', 'sort_key', 'created_by'])]
class CourseNode extends Model
{
    use HasUuids, SoftDeletes;

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<SchemaLevel, $this> */
    public function schemaLevel(): BelongsTo
    {
        return $this->belongsTo(SchemaLevel::class);
    }

    /** @return BelongsTo<CourseNode, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(CourseNode::class, 'parent_id');
    }

    /** @return HasMany<CourseNode, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(CourseNode::class, 'parent_id')->orderBy('sort_key');
    }

    /** @return HasMany<ContentBlock, $this> */
    public function blocks(): HasMany
    {
        return $this->hasMany(ContentBlock::class)->orderBy('sort_key');
    }

    /** @return HasMany<Assessment, $this> */
    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    /**
     * Every descendant, in tree order, via the ltree path. One query.
     *
     * @return Collection<int, static>
     */
    public function descendants(): Collection
    {
        return static::query()
            ->whereRaw('path <@ ?::ltree', [$this->path])
            ->whereKeyNot($this->getKey())
            ->orderBy('path')
            ->get();
    }

    /**
     * The levels a child of this node may take, with remaining capacity.
     *
     * `remaining` is null when max_occurrences is unbounded. The editor renders
     * "+ Add Lesson" from `plural_name` and disables it at zero.
     *
     * @return list<array{schema_level_id: string, name: string, plural_name: string, numbering_style: string, remaining: int<0, max>|null}>
     */
    public function allowedChildLevels(): array
    {
        $childLevels = SchemaLevel::query()
            ->where('parent_level_id', $this->schema_level_id)
            ->orderBy('sort_key')
            ->get();

        // Built from a bare query, not from children(): that relation carries an
        // ORDER BY sort_key, and Postgres rejects ordering by a column absent
        // from the GROUP BY.
        $counts = static::query()
            ->where('parent_id', $this->getKey())
            ->selectRaw('schema_level_id, count(*) as total')
            ->groupBy('schema_level_id')
            ->pluck('total', 'schema_level_id');

        return $childLevels->map(function (SchemaLevel $level) use ($counts): array {
            $used = (int) ($counts[$level->id] ?? 0);

            return [
                'schema_level_id' => $level->id,
                'name' => $level->name,
                'plural_name' => $level->plural_name,
                'numbering_style' => $level->numbering_style,
                'remaining' => $level->max_occurrences === null
                    ? null
                    : max(0, $level->max_occurrences - $used),
            ];
        })->values()->all();
    }

    public function permitsBlockType(string $type): bool
    {
        return $this->schemaLevel->permitsBlockType($type);
    }
}
