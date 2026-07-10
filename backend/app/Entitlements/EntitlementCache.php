<?php

namespace App\Entitlements;

use Closure;
use Illuminate\Contracts\Cache\Repository;

/**
 * Entitlements change rarely and are read on every request.
 *
 * Two invalidation scopes:
 *
 *  - **Per user** — a comp grant, a subscription webhook, a roster change. One
 *    key to forget.
 *  - **Global** — a course joins a bundle, so every holder of that bundle gains
 *    it at once. Scanning for the affected users is unbounded, so the cache key
 *    carries a version counter and bumping it invalidates everyone in one write.
 *
 * The five-minute TTL bounds the damage from an invalidation somebody forgot.
 * Do not raise it to an hour because a dashboard looked slow.
 */
final class EntitlementCache
{
    private const TTL_SECONDS = 300;

    private const VERSION_KEY = 'ent:version';

    public function __construct(private readonly Repository $cache) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $resolve
     * @return T
     */
    public function remember(string $userId, ?string $clientId, Closure $resolve): mixed
    {
        return $this->cache->remember($this->key($userId, $clientId), self::TTL_SECONDS, $resolve);
    }

    /**
     * The client context is part of the key, so one user may have several
     * entries. Bumping a per-user counter invalidates all of them without
     * enumerating every client they have ever launched from.
     */
    public function forgetUser(string $userId): void
    {
        $this->cache->increment($this->userVersionKey($userId));
    }

    /** A course joined or left a product. Everyone's entitlements may have changed. */
    public function forgetEveryone(): void
    {
        $this->cache->increment(self::VERSION_KEY);
    }

    private function key(string $userId, ?string $clientId): string
    {
        $version = $this->cache->get(self::VERSION_KEY, 0);
        $userVersion = $this->cache->get($this->userVersionKey($userId), 0);

        return sprintf('ent:v%s:u%s:%s:%s', $version, $userVersion, $userId, $clientId ?? 'b2c');
    }

    /**
     * Version counters are never evicted — they must outlive the entries they
     * invalidate, or a stale entry resurfaces. One small integer per user who
     * has ever had a grant revoked; bounded by the user count, not by traffic.
     */
    private function userVersionKey(string $userId): string
    {
        return "ent:uv:{$userId}";
    }
}
