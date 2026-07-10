<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * `is_correct === null` after grading means a human must decide. That is the
 * signal that moves an attempt to `awaiting_review`.
 *
 * @property string $id
 * @property string $attempt_id
 * @property string $assessment_question_id
 * @property array<string, mixed> $response
 * @property bool|null $is_correct
 * @property numeric-string|null $points_awarded
 * @property string|null $grader_id
 * @property string|null $grader_note
 * @property Carbon $answered_at
 * @property-read AssessmentQuestion $assessmentQuestion
 */
#[Fillable([
    'attempt_id', 'assessment_question_id', 'response', 'is_correct',
    'points_awarded', 'grader_id', 'grader_note', 'answered_at',
])]
class AttemptAnswer extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'response' => 'array',
            'is_correct' => 'boolean',
            'answered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AssessmentAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(AssessmentAttempt::class, 'attempt_id');
    }

    /** @return BelongsTo<AssessmentQuestion, $this> */
    public function assessmentQuestion(): BelongsTo
    {
        return $this->belongsTo(AssessmentQuestion::class);
    }

    public function needsHumanGrading(): bool
    {
        return $this->is_correct === null;
    }
}
