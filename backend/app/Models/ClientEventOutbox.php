<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One event, queued for one client, at one gapless sequence number.
 *
 * @property string $client_id
 * @property int $sequence
 * @property string $event_id
 * @property Carbon $event_occurred_at
 * @property Carbon|null $delivered_at
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $next_attempt_at
 * @property Carbon $created_at
 */
#[Fillable([
    'client_id', 'sequence', 'event_id', 'event_occurred_at',
    'delivered_at', 'attempts', 'last_error', 'next_attempt_at',
])]
class ClientEventOutbox extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'client_event_outbox';

    protected function casts(): array
    {
        return [
            'event_occurred_at' => 'datetime',
            'delivered_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'created_at' => 'datetime',
            'sequence' => 'integer',
            'attempts' => 'integer',
        ];
    }
}
