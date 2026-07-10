<?php

namespace App\Entitlements;

use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The single answer to the single question:
 *
 *   may this user, in this session context, read this course right now?
 *
 * Answering it in two places — one branch for B2B, one for B2C — guarantees the
 * two drift, and the drift is a paid-content leak. There is exactly one
 * resolver, and every caller goes through it.
 *
 * `$clientId` comes from the session token's `cid` claim, never from a request
 * parameter (docs/10 §8). A B2C session passes null.
 */
final class EntitlementResolver
{
    /**
     * Sources are consulted in this order, and the first hit wins.
     *
     * **Client entitlement is checked first.** A student launching from ABC
     * School reads the course under ABC's contract, and the resulting activity
     * is reported to ABC — even if that student also holds a personal
     * subscription. Attribution follows the session context, not the cheapest
     * grant. Backwards, and your reporting silently omits students.
     *
     * @param  list<GrantSource>  $sources  in priority order
     */
    public function __construct(
        private readonly EntitlementCache $cache,
        private readonly array $sources,
    ) {}

    /**
     * The grant under which this user may read this course, or null.
     *
     * A course with no publication is unreadable by anyone, however entitled:
     * there is nothing to read.
     */
    public function grantFor(User $user, Course $course, ?string $clientId = null): ?Grant
    {
        if ($course->latest_publication_id === null) {
            return null;
        }

        $grants = $this->grantsByProduct($user, $clientId);

        if ($grants === []) {
            return null;
        }

        $productIds = $this->productsCovering($course->id);

        foreach ($this->sources as $source) {
            foreach ($productIds as $productId) {
                $grant = $grants[$productId] ?? null;

                if ($grant?->source === $source->name() && ! $this->hasLapsed($grant)) {
                    return $grant;
                }
            }
        }

        return null;
    }

    /**
     * A grant that expires inside the cache TTL would otherwise keep reading as
     * valid for up to five more minutes. The expiry travels with the grant, so
     * check it on the way out rather than trusting the cache's clock.
     */
    private function hasLapsed(Grant $grant): bool
    {
        return $grant->expiresAt !== null && $grant->expiresAt->isPast();
    }

    public function entitles(User $user, Course $course, ?string $clientId = null): bool
    {
        return $this->grantFor($user, $course, $clientId) !== null;
    }

    /**
     * The learner's catalogue.
     *
     * Not an Eloquent scope. Two implementations of an access rule is one
     * implementation and one paid-content leak.
     *
     * @return Collection<int, Course>
     */
    public function coursesFor(User $user, ?string $clientId = null): Collection
    {
        $productIds = array_keys($this->grantsByProduct($user, $clientId));

        if ($productIds === []) {
            return collect();
        }

        return Course::query()
            ->whereNotNull('latest_publication_id')
            ->whereIn('id', DB::table('product_courses')
                ->whereIn('product_id', $productIds)
                ->select('course_id'))
            ->orderBy('title')
            ->get();
    }

    /**
     * Every grant this user holds, keyed by product. Cached for five minutes.
     *
     * @return array<string, Grant>
     */
    private function grantsByProduct(User $user, ?string $clientId): array
    {
        $cached = $this->cache->remember(
            $user->id,
            $clientId,
            function () use ($user, $clientId) {
                $grants = [];

                // Reverse order so the highest-priority source overwrites the
                // rest: one product, one winning grant.
                foreach (array_reverse($this->sources) as $source) {
                    foreach ($source->grantsFor($user, $clientId) as $productId => $grant) {
                        $grants[$productId] = $grant->toArray();
                    }
                }

                return $grants;
            },
        );

        return array_map($this->hydrate(...), $cached);
    }

    /** @param array<string, mixed> $payload */
    private function hydrate(array $payload): Grant
    {
        return new Grant(
            source: $payload['source'],
            clientId: $payload['client_id'],
            referenceId: $payload['reference_id'],
            expiresAt: $payload['expires_at'] === null ? null : Carbon::parse($payload['expires_at']),
        );
    }

    /**
     * Products that contain this course. Includes bundles and catalogues, which
     * is the entire reason the product layer exists.
     *
     * @return list<string>
     */
    private function productsCovering(string $courseId): array
    {
        return DB::table('product_courses')
            ->join('products', 'products.id', '=', 'product_courses.product_id')
            ->where('product_courses.course_id', $courseId)
            ->where('products.status', 'active')
            ->pluck('product_courses.product_id')
            ->all();
    }
}
