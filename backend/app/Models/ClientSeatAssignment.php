<?php

namespace App\Models;

use App\Entitlements\EntitlementCache;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A named seat under the `assigned` seat model.
 *
 * @property string $client_entitlement_id
 * @property string $client_user_id
 */
#[Fillable(['client_entitlement_id', 'client_user_id', 'released_at'])]
class ClientSeatAssignment extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'client_seat_assignments';

    /**
     * Assigning or releasing a seat changes who may read. The cache busts
     * itself; leaving it to the caller means a released seat keeps reading for
     * the full TTL.
     */
    protected static function booted(): void
    {
        $forget = function (ClientSeatAssignment $seat) {
            $userId = ClientUser::whereKey($seat->client_user_id)->value('user_id');

            if ($userId !== null) {
                app(EntitlementCache::class)->forgetUser($userId);
            }
        };

        static::saved($forget);
        static::deleted($forget);
    }

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'released_at' => 'datetime'];
    }
}
