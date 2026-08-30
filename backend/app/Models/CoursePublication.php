<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An immutable snapshot of a course at a moment in time. Learners read only
 * these. A database trigger refuses every UPDATE.
 *
 * @property string $id
 * @property string $course_id
 * @property int $number
 * @property string $schema_version_id
 * @property array<string, mixed> $snapshot
 * @property string $snapshot_etag
 * @property list<array<string, mixed>> $media_manifest
 * @property string|null $changelog
 * @property string|null $published_by
 * @property Carbon $published_at
 * @property-read Course $course
 */
#[Fillable([
    'course_id', 'number', 'schema_version_id', 'snapshot', 'snapshot_etag',
    'media_manifest', 'lessons_count', 'changelog', 'published_by',
])]
class CoursePublication extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'media_manifest' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
