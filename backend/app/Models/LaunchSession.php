<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One validated click on a resource link.
 *
 * Every activity event this session produces carries its `client_id`. That is
 * what makes reporting back to that client — and only that client — correct by
 * construction.
 *
 * @property string $id
 * @property string $client_id
 * @property string $client_user_id
 * @property string|null $resource_link_id
 * @property string|null $client_context_id
 * @property string $message_type
 * @property string $jti
 * @property string $nonce
 * @property Carbon $expires_at
 * @property Carbon|null $exchanged_at
 * @property-read ClientUser $clientUser
 * @property-read ResourceLink|null $resourceLink
 */
#[Fillable([
    'client_id', 'client_user_id', 'resource_link_id', 'client_context_id',
    'message_type', 'jti', 'nonce', 'ip', 'user_agent', 'expires_at', 'exchanged_at',
])]
class LaunchSession extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'expires_at' => 'datetime',
            'exchanged_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ClientUser, $this> */
    public function clientUser(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class);
    }

    /** @return BelongsTo<ResourceLink, $this> */
    public function resourceLink(): BelongsTo
    {
        return $this->belongsTo(ResourceLink::class);
    }
}
