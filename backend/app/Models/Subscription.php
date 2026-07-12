<?php

namespace App\Models;

use App\Entitlements\EntitlementCache;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $plan_id
 * @property string $provider
 * @property string|null $provider_sub_id
 * @property string $status
 * @property Carbon|null $current_period_start
 * @property Carbon|null $current_period_end
 * @property Carbon|null $cancel_at
 * @property Carbon|null $canceled_at
 * @property Carbon|null $provider_event_at
 * @property-read Plan $plan
 */
#[Fillable([
    'user_id', 'plan_id', 'provider', 'provider_sub_id', 'status',
    'current_period_start', 'current_period_end', 'cancel_at', 'canceled_at',
    'provider_event_at',
])]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory, HasUuids;

    /** Opened at the provider, not yet paid for. Entitles nothing. */
    public const PENDING = 'pending';

    public const TRIALING = 'trialing';

    public const ACTIVE = 'active';

    public const PAST_DUE = 'past_due';

    public const CANCELED = 'canceled';

    public const EXPIRED = 'expired';

    /**
     * Statuses that still entitle, provided the paid-for period has not ended.
     *
     * `past_due` is included on purpose: a failed renewal starts a dunning
     * cycle, and locking a learner out on the first declined card — mid-term,
     * while the provider is still retrying — loses the customer you were trying
     * to bill. `canceled` is included because cancelling means "do not renew",
     * not "refund the month I already paid for".
     */
    private const ENTITLING = [self::TRIALING, self::ACTIVE, self::PAST_DUE, self::CANCELED];

    protected static function booted(): void
    {
        $forget = fn (Subscription $s) => app(EntitlementCache::class)->forgetUser($s->user_id);

        static::saved($forget);
        static::deleted($forget);
    }

    protected function casts(): array
    {
        return [
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancel_at' => 'datetime',
            'canceled_at' => 'datetime',
            'provider_event_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isEntitling(): bool
    {
        return in_array($this->status, self::ENTITLING, true)
            && $this->current_period_end !== null
            && $this->current_period_end->isFuture();
    }

    /** @param Builder<Subscription> $query */
    public function scopeEntitling(Builder $query): void
    {
        $query->whereIn('status', self::ENTITLING)
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '>', now());
    }
}
