<?php

namespace App\Models;

use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `price_minor` is an integer count of paise/cents. Never a float.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $product_id
 * @property int $price_minor
 * @property string $currency
 * @property string $interval
 * @property int $trial_days
 * @property string|null $provider_ref
 * @property string $status
 * @property-read Product $product
 */
#[Fillable([
    'code', 'name', 'product_id', 'price_minor', 'currency',
    'interval', 'trial_days', 'provider_ref', 'status',
])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory, HasUuids;

    public const MONTHLY = 'month';

    public const YEARLY = 'year';

    public const ONE_TIME = 'one_time';

    protected function casts(): array
    {
        return ['price_minor' => 'integer', 'trial_days' => 'integer'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
