<?php

namespace App\Models;

use App\Entitlements\EntitlementCache;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The mapping from a client's own user id to an LMS account.
 *
 * `external_email` is a claim by the client about a third party. It has exactly
 * the trust level of the client: enough to display, never enough to authenticate.
 * See docs/10 §7.
 *
 * @property string $id
 * @property string $client_id
 * @property string $external_user_id
 * @property string $user_id
 * @property string $role
 * @property string|null $external_name
 * @property string|null $external_email
 * @property string $status
 * @property Carbon|null $last_seen_at
 * @property-read Client $client
 * @property-read User $user
 */
#[Fillable([
    'client_id', 'external_user_id', 'user_id', 'role',
    'external_name', 'external_email', 'status', 'last_seen_at',
])]
class ClientUser extends Model
{
    use HasUuids;

    public $timestamps = false;

    public const LEARNER = 'learner';

    public const INSTRUCTOR = 'instructor';

    public const CLIENT_ADMIN = 'client_admin';

    protected static function booted(): void
    {
        // Deactivating a roster member must revoke their access now, not in
        // five minutes when the cache expires.
        $forget = fn (ClientUser $cu) => app(EntitlementCache::class)->forgetUser($cu->user_id);

        static::saved($forget);
        static::deleted($forget);
    }

    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
