<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Request;

/**
 * Administrative acts. Learning acts belong in activity_events (docs/12) —
 * different volume, different consumers, different privacy exposure.
 *
 * The table is range-partitioned on created_at and has a composite primary key
 * (id, created_at), so it has no single incrementing key Eloquent can rely on.
 *
 * @property int $id
 * @property string|null $actor_id
 * @property string $action
 * @property string $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property Carbon $created_at
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public static function record(
        ?User $actor,
        string $action,
        Model $subject,
        ?array $before = null,
        ?array $after = null,
    ): void {
        static::create([
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'before' => $before,
            'after' => $after,
            'ip' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 500),
        ]);
    }
}
