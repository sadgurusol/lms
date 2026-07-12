<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Opaque, single-use, 60-second.
 *
 * The redirect that carries it lands in browser history, access logs, `Referer`
 * headers and screen recordings. An access token there is a compromised access
 * token; a burnt ticket is worthless.
 *
 * @property string $token_hash
 * @property string $launch_session_id
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 */
#[Fillable(['token_hash', 'launch_session_id', 'expires_at', 'used_at'])]
class LaunchTicket extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'token_hash';

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    /** @return BelongsTo<LaunchSession, $this> */
    public function launchSession(): BelongsTo
    {
        return $this->belongsTo(LaunchSession::class);
    }

    /** We store sha256(ticket); the plaintext exists only in the redirect. */
    public static function hash(string $ticket): string
    {
        return hash('sha256', $ticket);
    }
}
