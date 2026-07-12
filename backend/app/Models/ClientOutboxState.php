<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $client_id
 * @property int $next_sequence
 * @property Carbon|null $parked_at
 * @property string|null $parked_reason
 */
#[Fillable(['client_id', 'next_sequence', 'parked_at', 'parked_reason'])]
class ClientOutboxState extends Model
{
    public $incrementing = false;

    protected $table = 'client_outbox_state';

    protected $primaryKey = 'client_id';

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['parked_at' => 'datetime', 'next_sequence' => 'integer'];
    }

    public function isParked(): bool
    {
        return $this->parked_at !== null;
    }
}
