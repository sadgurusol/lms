<?php

namespace App\Models;

use App\Entitlements\EntitlementCache;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A one-time buy. Permanent, unless refunded.
 *
 * @property string $id
 * @property string $user_id
 * @property string $product_id
 * @property int $amount_minor
 * @property string $currency
 * @property string $provider
 * @property string|null $provider_ref
 * @property Carbon|null $refunded_at
 */
#[Fillable(['user_id', 'product_id', 'amount_minor', 'currency', 'provider', 'provider_ref', 'refunded_at'])]
class Purchase extends Model
{
    use HasUuids;

    protected static function booted(): void
    {
        $forget = fn (Purchase $p) => app(EntitlementCache::class)->forgetUser($p->user_id);

        static::saved($forget);
        static::deleted($forget);
    }

    protected function casts(): array
    {
        return ['refunded_at' => 'datetime', 'amount_minor' => 'integer'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @param Builder<Purchase> $query */
    public function scopeEntitling(Builder $query): void
    {
        $query->whereNull('refunded_at');
    }
}
