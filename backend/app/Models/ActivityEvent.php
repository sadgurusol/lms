<?php

namespace App\Models;

use App\Activity\Verb;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Range-partitioned on occurred_at, with a composite primary key. Global
 * idempotency lives in `activity_event_keys` — Postgres cannot enforce
 * uniqueness on a column that omits the partition key.
 *
 * @property string $id
 * @property Carbon $occurred_at
 * @property string $user_id
 * @property string|null $client_id
 * @property string|null $client_user_id
 * @property string $verb
 * @property string $course_id
 * @property string $publication_id
 * @property string|null $client_context_id
 * @property string|null $launch_session_id
 * @property string|null $course_node_id
 * @property string|null $assessment_id
 * @property string|null $attempt_id
 * @property string|null $grant_source
 * @property bool $over_seat
 * @property array<string, mixed> $payload
 * @property array<string, mixed> $device
 * @property Carbon $received_at
 */
#[Fillable([
    'id', 'occurred_at', 'user_id', 'client_id', 'client_user_id', 'client_context_id',
    'launch_session_id', 'verb', 'course_id', 'publication_id', 'course_node_id',
    'assessment_id', 'attempt_id', 'grant_source', 'over_seat', 'payload', 'device',
])]
class ActivityEvent extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'payload' => 'array',
            'device' => 'array',
            'over_seat' => 'boolean',
        ];
    }

    public function verb(): Verb
    {
        return Verb::from($this->verb);
    }

    /** A B2C event belongs to no client and is reported to nobody. */
    public function isClientAttributed(): bool
    {
        return $this->client_id !== null;
    }
}
