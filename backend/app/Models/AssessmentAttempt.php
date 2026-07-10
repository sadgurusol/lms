<?php

namespace App\Models;

use App\Casts\PostgresUuidArray;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One learner's run at an assessment.
 *
 * `question_order` is frozen at start. Recomputing the shuffle on each request
 * would make an attempt unresumable and unauditable.
 *
 * @property string $id
 * @property string $assessment_id
 * @property string $publication_id
 * @property string $user_id
 * @property int $attempt_number
 * @property string $state
 * @property int $max_index_reached
 * @property list<string> $question_order
 * @property Carbon $started_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $submitted_at
 * @property Carbon|null $graded_at
 * @property numeric-string|null $score
 * @property numeric-string|null $max_score
 * @property bool|null $passed
 * @property array<string, mixed> $meta
 * @property-read Assessment $assessment
 * @property-read Collection<int, AttemptAnswer> $answers
 */
#[Fillable([
    'assessment_id', 'publication_id', 'user_id', 'attempt_number', 'state',
    'question_order', 'expires_at', 'meta',
])]
class AssessmentAttempt extends Model
{
    use HasUuids;

    public $timestamps = false;

    public const IN_PROGRESS = 'in_progress';

    public const SUBMITTED = 'submitted';

    public const AWAITING_REVIEW = 'awaiting_review';

    public const GRADED = 'graded';

    public const EXPIRED = 'expired';

    protected function casts(): array
    {
        return [
            'question_order' => PostgresUuidArray::class,
            'meta' => 'array',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'graded_at' => 'datetime',
            'passed' => 'boolean',
        ];
    }

    /** @return BelongsTo<Assessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /** @return HasMany<AttemptAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(AttemptAnswer::class, 'attempt_id');
    }

    public function isInProgress(): bool
    {
        return $this->state === self::IN_PROGRESS;
    }

    /** Server-authoritative. Never trust a client clock. */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
