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
 * Complimentary access, with a reason and an expiry, audited.
 *
 * Content reviewers need to read published courses without a subscription.
 * Support needs to reproduce a learner's bug. Without this table the
 * alternative always emerges: a hidden `if ($user->email endsWith '@ours')`
 * buried in the resolver.
 *
 * @property string $id
 * @property string $user_id
 * @property string $product_id
 * @property string $reason
 * @property string|null $granted_by
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 */
#[Fillable(['user_id', 'product_id', 'reason', 'granted_by', 'starts_at', 'ends_at'])]
class CompGrant extends Model
{
    use HasUuids;

    public const REASON_STAFF = 'staff';

    public const REASON_REVIEWER = 'reviewer';

    public const REASON_TRIAL = 'trial';

    public const REASON_SUPPORT = 'support';

    protected static function booted(): void
    {
        // The cache busts itself. See CourseGrant for why leaving this to the
        // caller is not an option.
        $forget = fn (CompGrant $grant) => app(EntitlementCache::class)->forgetUser($grant->user_id);

        static::saved($forget);
        static::deleted($forget);
    }

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @param Builder<CompGrant> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('starts_at', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }
}
