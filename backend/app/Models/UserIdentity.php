<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One human, many ways to sign in.
 *
 * `provider` is 'password', an SSO provider, or 'client:{slug}' for a user
 * provisioned by a B2B launch. A launch may only ever create or reuse a
 * 'client:{slug}' identity — never a 'password' one, and never by matching an
 * email address. See docs/10-clients-and-launch.md §7.
 *
 * @property string $id
 * @property string $user_id
 * @property string $provider
 * @property string $provider_uid
 * @property Carbon|null $verified_at
 */
#[Fillable(['user_id', 'provider', 'provider_uid', 'verified_at'])]
class UserIdentity extends Model
{
    use HasUuids;

    public const PROVIDER_PASSWORD = 'password';

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function clientProvider(string $clientSlug): string
    {
        return "client:{$clientSlug}";
    }
}
