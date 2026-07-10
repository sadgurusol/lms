<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Lexicographically-ordered sort keys ("fractional indexing").
 *
 * Inserting between "V" and "W" yields "VV": one row updated, not N. Integer
 * `position` columns force a renumber of every sibling on each drag, and two
 * authors dragging concurrently scramble the order. Reordering a 200-lesson
 * unit is the thing this avoids.
 *
 * Keys are fractions in (0, 1) written in base 62 with the leading "0." implied,
 * over the digit set 0-9 A-Z a-z. That digit set is in ASCII order, so byte
 * comparison and numeric comparison agree — **provided the column is compared
 * byte-wise**. See the `COLLATE "C"` note on sort_key columns; under a
 * locale-aware collation "a" sorts before "B" and the whole scheme collapses.
 *
 * Adapted from the algorithm in rocicorp/fractional-indexing.
 */
final class FractionalIndex
{
    private const DIGITS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /**
     * A key strictly between $prev and $next.
     *
     * Pass null for $prev to insert at the head, null for $next to append.
     */
    public static function between(?string $prev, ?string $next): string
    {
        // strcmp, never `>=`. PHP compares two numeric strings *numerically*, so
        // '0002' >= '001' evaluates as 2 >= 1 — true — even though byte-wise
        // '0002' sorts before '001', which is how Postgres orders them under
        // COLLATE "C". Head insertions produce exactly these all-digit keys.
        if ($prev !== null && $next !== null && strcmp($prev, $next) >= 0) {
            throw new InvalidArgumentException("Sort keys out of order: [{$prev}] is not before [{$next}].");
        }

        return self::midpoint($prev ?? '', $next);
    }

    /** Evenly spaced keys for building a list from scratch. */
    public static function sequence(int $count): array
    {
        $keys = [];
        $prev = null;

        for ($i = 0; $i < $count; $i++) {
            $keys[] = $prev = self::between($prev, null);
        }

        return $keys;
    }

    public static function isValid(string $key): bool
    {
        return $key !== ''
            && ! str_ends_with($key, '0')
            && strspn($key, self::DIGITS) === strlen($key);
    }

    private static function midpoint(string $a, ?string $b): string
    {
        // A trailing '0' is the one representation that has no midpoint below
        // it: "X0" and "X" denote the same fraction. Keys never end in '0', so
        // encountering one means a key was hand-written or corrupted.
        if (str_ends_with($a, '0') || ($b !== null && str_ends_with($b, '0'))) {
            throw new InvalidArgumentException('Sort keys must not end in "0".');
        }

        if ($b !== null) {
            // Strip the common prefix and recurse on the remainder.
            $n = 0;
            while (($a[$n] ?? '0') === ($b[$n] ?? null)) {
                $n++;
            }

            if ($n > 0) {
                return substr($b, 0, $n).self::midpoint(substr($a, $n), substr($b, $n));
            }
        }

        $digitA = $a !== '' ? strpos(self::DIGITS, $a[0]) : 0;
        $digitB = $b !== null && $b !== '' ? strpos(self::DIGITS, $b[0]) : strlen(self::DIGITS);

        if ($digitB - $digitA > 1) {
            return self::DIGITS[(int) round(0.5 * ($digitA + $digitB))];
        }

        // The digits are adjacent: descend a place.
        if ($b !== null && strlen($b) > 1) {
            return substr($b, 0, 1);
        }

        return self::DIGITS[$digitA].self::midpoint(substr($a, 1), null);
    }
}
