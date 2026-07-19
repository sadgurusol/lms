<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An AI course-generation run. See docs/14-course-generation.md.
 *
 * @property string $id
 * @property string $requested_by
 * @property string $schema_version_id
 * @property string|null $course_id
 * @property string $name
 * @property string $source_type
 * @property string|null $brief
 * @property string|null $pdf_path
 * @property string $status
 * @property string|null $error
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property Carbon|null $created_at
 */
#[Fillable([
    'requested_by', 'schema_version_id', 'course_id', 'name', 'source_type',
    'brief', 'pdf_path', 'status', 'error', 'input_tokens', 'output_tokens',
])]
class CourseGeneration extends Model
{
    use HasUuids;

    public const SOURCE_PDF = 'pdf';

    public const SOURCE_BRIEF = 'brief';

    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    /** @return BelongsTo<SchemaVersion, $this> */
    public function schemaVersion(): BelongsTo
    {
        return $this->belongsTo(SchemaVersion::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
