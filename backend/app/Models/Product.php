<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * The sellable unit: a course, a bundle, or a catalogue.
 *
 * Adding a course to a bundle grants it to every client and subscriber holding
 * that bundle. That is what you want, and it is also why the change is audited
 * and busts the entitlement cache globally.
 *
 * @property string $id
 * @property string $sku
 * @property string $name
 * @property string $kind
 * @property string $status
 * @property array<string, mixed> $metadata
 */
#[Fillable(['sku', 'name', 'kind', 'status', 'metadata'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasUuids;

    public const KIND_COURSE = 'course';

    public const KIND_BUNDLE = 'bundle';

    public const KIND_CATALOG = 'catalog';

    public const STATUS_ACTIVE = 'active';

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    /** @return BelongsToMany<Course, $this> */
    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'product_courses')->withPivot('added_at');
    }

    /** @param Builder<Product> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }
}
