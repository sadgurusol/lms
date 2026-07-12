<?php

namespace App\Models;

use Database\Factories\QuestionBankFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bank is course-scoped, or global when `course_id` is null.
 *
 * @property string $id
 * @property string $name
 * @property string|null $course_id
 * @property string|null $created_by
 */
#[Fillable(['name', 'course_id', 'created_by'])]
class QuestionBank extends Model
{
    /** @use HasFactory<QuestionBankFactory> */
    use HasFactory, HasUuids;

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return HasMany<Question, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    public function isGlobal(): bool
    {
        return $this->course_id === null;
    }
}
