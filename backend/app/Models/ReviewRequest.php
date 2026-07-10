<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $course_id
 * @property string $submitted_by
 * @property string|null $assigned_to
 * @property string $state
 * @property string|null $note
 * @property Carbon|null $due_at
 * @property Carbon|null $decided_at
 * @property string|null $decided_by
 * @property string|null $decision_note
 * @property-read Course $course
 */
#[Fillable(['course_id', 'submitted_by', 'assigned_to', 'state', 'note', 'due_at', 'decided_at', 'decided_by', 'decision_note'])]
class ReviewRequest extends Model
{
    use HasUuids;

    public const STATE_OPEN = 'open';

    public const STATE_APPROVED = 'approved';

    public const STATE_CHANGES_REQUESTED = 'changes_requested';

    public const STATE_WITHDRAWN = 'withdrawn';

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return HasMany<ReviewComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(ReviewComment::class);
    }

    public function isOpen(): bool
    {
        return $this->state === self::STATE_OPEN;
    }
}
