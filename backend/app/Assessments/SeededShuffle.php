<?php

namespace App\Assessments;

/**
 * Deterministic shuffle, seeded by a string.
 *
 * The seed is the attempt id, so a learner who closes the app mid-test and
 * returns sees the same question order and the same option order. A shuffle
 * recomputed per request is a shuffle that changes under the learner's feet.
 *
 * Not shuffle()/mt_rand(): those consume the global RNG state, so one attempt's
 * order would depend on how many other things happened in the same process.
 */
final class SeededShuffle
{
    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    public static function apply(array $items, string $seed): array
    {
        // Fisher–Yates driven by a keyed hash stream. Deterministic, and
        // independent of any global RNG.
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = self::intAt($seed, $i) % ($i + 1);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return array_values($items);
    }

    private static function intAt(string $seed, int $index): int
    {
        // 7 hex chars => at most 2^28, always positive on 32-bit PHP too.
        return (int) hexdec(substr(hash('sha256', "{$seed}:{$index}"), 0, 7));
    }
}
