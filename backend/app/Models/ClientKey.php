<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $client_id
 * @property string $kid
 * @property string $algorithm
 * @property string|null $public_key
 * @property string|null $jwks_url
 * @property string $status
 * @property Carbon|null $not_before
 * @property Carbon|null $expires_at
 */
#[Fillable(['client_id', 'kid', 'algorithm', 'public_key', 'jwks_url', 'status', 'not_before', 'expires_at'])]
class ClientKey extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['not_before' => 'datetime', 'expires_at' => 'datetime'];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Keys that may verify a signature right now.
     *
     * `rotating` still verifies: a client mid-rotation signs with the new key
     * while tokens signed by the old one are still in flight.
     *
     * @param  Builder<ClientKey>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->whereIn('status', ['active', 'rotating'])
            ->where(fn (Builder $q) => $q->whereNull('not_before')->orWhere('not_before', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
