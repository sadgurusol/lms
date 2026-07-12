<?php

namespace App\Launch;

use Illuminate\Contracts\Cache\Repository;

/**
 * A launch token may be presented exactly once.
 *
 * Redis is the fast path. Postgres is the truth: `launch_sessions` carries a
 * unique index on `(client_id, jti)`, so a replay survives a cache flush. Both,
 * not either — a cache is not a security control, and a database round-trip on
 * every launch is not a bottleneck worth removing.
 */
final class ReplayGuard
{
    /** Long enough to outlive any token we accept (exp - iat ≤ 120s, skew 60s). */
    private const TTL_SECONDS = 600;

    public function __construct(private readonly Repository $cache) {}

    /** @return bool true if this is the first time we have seen it */
    public function claim(string $clientId, string $kind, string $value): bool
    {
        return $this->cache->add($this->key($clientId, $kind, $value), true, self::TTL_SECONDS);
    }

    public function seen(string $clientId, string $kind, string $value): bool
    {
        return $this->cache->has($this->key($clientId, $kind, $value));
    }

    private function key(string $clientId, string $kind, string $value): string
    {
        return sprintf('launch:%s:%s:%s', $kind, $clientId, hash('sha256', $value));
    }
}
