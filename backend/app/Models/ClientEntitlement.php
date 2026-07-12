<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * ABC School's contract: this product, this many seats, these dates.
 *
 * @property string $id
 * @property string $client_id
 * @property string $product_id
 * @property string $seat_model
 * @property int|null $seat_limit
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property string $status
 */
#[Fillable([
    'client_id', 'product_id', 'seat_model', 'seat_limit',
    'starts_at', 'ends_at', 'status', 'contract_ref',
])]
class ClientEntitlement extends Model
{
    use HasUuids;

    public const ASSIGNED = 'assigned';

    public const ACTIVE_SEATS = 'active';

    public const UNLIMITED = 'unlimited';

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'seat_limit' => 'integer'];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<ClientSeatAssignment, $this> */
    public function seatAssignments(): HasMany
    {
        return $this->hasMany(ClientSeatAssignment::class);
    }

    /**
     * Count of distinct client users who have read anything under this
     * entitlement in the current period. Drives the `active` seat model's
     * overage reporting — it never blocks a read.
     */
    public function seatsUsed(): int
    {
        return match ($this->seat_model) {
            self::ASSIGNED => $this->seatAssignments()->whereNull('released_at')->count(),
            self::UNLIMITED => 0,
            default => ClientUser::where('client_id', $this->client_id)
                ->where('status', 'active')
                ->whereNotNull('last_seen_at')
                ->count(),
        };
    }

    public function isOverSeats(): bool
    {
        return $this->seat_limit !== null && $this->seatsUsed() > $this->seat_limit;
    }

    /** @param Builder<ClientEntitlement> $query */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', 'active')
            ->where('starts_at', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }
}
