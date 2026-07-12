<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The raw webhook, stored before it is processed.
 *
 * @property string $id
 * @property string $provider
 * @property string $provider_event_id
 * @property string $type
 * @property array<string, mixed> $payload
 * @property Carbon|null $occurred_at
 * @property Carbon|null $processed_at
 * @property string|null $error
 */
#[Fillable(['provider', 'provider_event_id', 'type', 'payload', 'occurred_at', 'processed_at', 'error'])]
class WebhookEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
