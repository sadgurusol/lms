<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts a Postgres `text[]` column to and from a PHP list.
 *
 * Eloquent's `array` cast is JSON; feeding JSON to a text[] column fails, and
 * feeding a PHP array to it silently stringifies to "Array".
 *
 * The parser is hand-rolled because `str_getcsv` does not *unescape* its escape
 * character — it only stops the enclosure from terminating. Postgres emits
 * `{"quoted\"tag"}`, and str_getcsv hands back `quoted\"tag`.
 *
 * @implements CastsAttributes<list<string>, list<string>>
 */
class PostgresTextArray implements CastsAttributes
{
    /** @return list<string> */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '' || $value === '{}') {
            return [];
        }

        return $this->parse(trim((string) $value, '{}'));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        // Always quote: an unquoted element containing a comma, a brace or a
        // space changes meaning inside an array literal.
        $items = array_map(
            fn (string $item) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $item).'"',
            (array) $value,
        );

        return '{'.implode(',', $items).'}';
    }

    /** @return list<string> */
    private function parse(string $literal): array
    {
        $items = [];
        $current = '';
        $inQuotes = false;
        $escaped = false;

        foreach (str_split($literal) as $char) {
            if ($escaped) {
                $current .= $char;
                $escaped = false;

                continue;
            }

            match (true) {
                $char === '\\' => $escaped = true,
                $char === '"' => $inQuotes = ! $inQuotes,
                $char === ',' && ! $inQuotes => [$items[] = $current, $current = ''],
                default => $current .= $char,
            };
        }

        $items[] = $current;

        // A bare NULL element is Postgres's null, not the four-character string.
        return array_values(array_filter($items, fn (string $item) => $item !== '' && $item !== 'NULL'));
    }
}
