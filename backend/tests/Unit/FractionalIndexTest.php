<?php

use App\Support\FractionalIndex;

/**
 * Byte-wise ordering assertions.
 *
 * Never `expect($a)->toBeGreaterThan($b)` on sort keys: PHP compares numeric
 * strings numerically, so '0002' > '001' is true while byte-wise — and in
 * Postgres under COLLATE "C" — it is false. The tests must compare the way the
 * database does, or they will bless a broken ordering.
 */
function expectOrdered(string ...$keys): void
{
    foreach (array_slice($keys, 1) as $i => $next) {
        expect(strcmp($keys[$i], $next))->toBeLessThan(
            0, "expected [{$keys[$i]}] to sort before [{$next}]"
        );
    }
}

it('generates a first key', function () {
    expect(FractionalIndex::between(null, null))->toBe('V');
});

it('appends after a key', function () {
    expectOrdered('V', FractionalIndex::between('V', null));
});

it('prepends before a key', function () {
    expectOrdered(FractionalIndex::between(null, 'V'), 'V');
});

it('inserts strictly between adjacent digits', function () {
    expectOrdered('V', FractionalIndex::between('V', 'W'), 'W');
});

/**
 * Regression: found by the tree property test at seed 42.
 *
 * Repeated head insertion generates all-digit keys. Under PHP's loose
 * comparison '0002' >= '001' is true, so the ordering guard rejected a pair
 * that is correctly ordered byte-wise.
 */
it('accepts all-digit keys that PHP would compare numerically', function () {
    expectOrdered('0002', '001');

    $mid = FractionalIndex::between('0002', '001');

    expectOrdered('0002', $mid, '001');
});

it('survives repeated insertion at the head', function () {
    $key = FractionalIndex::between(null, null);

    for ($i = 0; $i < 200; $i++) {
        $next = FractionalIndex::between(null, $key);

        expectOrdered($next, $key);
        $key = $next;
    }
});

it('rejects keys given out of order', function () {
    expect(fn () => FractionalIndex::between('W', 'V'))
        ->toThrow(InvalidArgumentException::class, 'not before');
});

it('rejects keys that end in zero', function () {
    expect(fn () => FractionalIndex::between('V0', null))
        ->toThrow(InvalidArgumentException::class, 'must not end in');
});

it('produces an ascending sequence', function () {
    $keys = FractionalIndex::sequence(50);
    $sorted = $keys;
    sort($sorted, SORT_STRING);

    expect($keys)->toBe($sorted)
        ->and(array_unique($keys))->toHaveCount(50);
});

it('never produces a key ending in zero', function () {
    $keys = FractionalIndex::sequence(100);

    foreach ($keys as $key) {
        expect(FractionalIndex::isValid($key))->toBeTrue("[{$key}] is not a valid sort key");
    }
});

/**
 * The operation the editor performs on every drag: repeatedly drop a new item
 * between the same two neighbours. Keys must stay ordered and stay short.
 */
it('survives a thousand insertions at the same position', function () {
    $left = FractionalIndex::between(null, null);
    $right = FractionalIndex::between($left, null);

    $inserted = [];
    for ($i = 0; $i < 1000; $i++) {
        $mid = FractionalIndex::between($left, $right);

        expectOrdered($left, $mid, $right);

        $inserted[] = $mid;
        $right = $mid;   // keep squeezing against the left neighbour
    }

    expect(strlen(end($inserted)))->toBeLessThan(200);
});

/**
 * Randomised: build a list by inserting at random positions, then assert the
 * keys sort into exactly the order the list is in.
 */
it('keeps sort order under random insertion', function () {
    mt_srand(20260710);
    $list = [FractionalIndex::between(null, null)];

    for ($i = 0; $i < 300; $i++) {
        $at = mt_rand(0, count($list));
        $prev = $at > 0 ? $list[$at - 1] : null;
        $next = $at < count($list) ? $list[$at] : null;

        array_splice($list, $at, 0, [FractionalIndex::between($prev, $next)]);
    }

    $sorted = $list;
    sort($sorted, SORT_STRING);

    expect($list)->toBe($sorted)
        ->and(array_unique($list))->toHaveCount(count($list));
});
