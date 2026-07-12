<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $publication_id
 * @property string $course_node_id
 * @property string $state
 * @property int $seconds_spent
 * @property int|null $last_position
 * @property Carbon|null $completed_at
 * @property Carbon|null $client_updated_at
 */
#[Fillable([
    'user_id', 'publication_id', 'course_node_id', 'state',
    'seconds_spent', 'last_position', 'completed_at', 'client_updated_at',
])]
class NodeProgress extends Model
{
    use HasUuids;

    protected $table = 'node_progress';

    public const NOT_STARTED = 'not_started';

    public const IN_PROGRESS = 'in_progress';

    public const COMPLETED = 'completed';

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'client_updated_at' => 'datetime',
            'seconds_spent' => 'integer',
            'last_position' => 'integer',
        ];
    }

    /** @return BelongsTo<CoursePublication, $this> */
    public function publication(): BelongsTo
    {
        return $this->belongsTo(CoursePublication::class, 'publication_id');
    }

    public function isCompleted(): bool
    {
        return $this->state === self::COMPLETED;
    }
}
