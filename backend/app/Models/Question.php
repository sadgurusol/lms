<?php

namespace App\Models;

use App\Assessments\QuestionType;
use App\Casts\PostgresTextArray;
use Database\Factories\QuestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * `grading` holds the answer key for types that do not use options.
 *
 * Never serialise this model to a learner. Use QuestionViewerResource.
 *
 * @property string $id
 * @property string $question_bank_id
 * @property string $type
 * @property array<string, mixed> $stem
 * @property array<string, mixed>|null $explanation
 * @property numeric-string $default_points
 * @property string|null $difficulty
 * @property array<string, mixed> $grading
 * @property list<string> $tags
 * @property string|null $media_id
 * @property-read Collection<int, QuestionOption> $options
 */
#[Fillable([
    'question_bank_id', 'type', 'stem', 'explanation', 'default_points',
    'difficulty', 'grading', 'tags', 'media_id', 'created_by',
])]
class Question extends Model
{
    /** @use HasFactory<QuestionFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'stem' => 'array',
            'explanation' => 'array',
            'grading' => 'array',
            'tags' => PostgresTextArray::class,
        ];
    }

    public function questionType(): QuestionType
    {
        return QuestionType::from($this->type);
    }

    /** @return BelongsTo<QuestionBank, $this> */
    public function questionBank(): BelongsTo
    {
        return $this->belongsTo(QuestionBank::class);
    }

    /** @return HasMany<QuestionOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('sort_key');
    }
}
