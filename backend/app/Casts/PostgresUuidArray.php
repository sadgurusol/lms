<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts a Postgres `uuid[]` column to and from an ordered PHP list.
 *
 * @implements CastsAttributes<list<string>, list<string>>
 */
class PostgresUuidArray implements CastsAttributes
{
    /** @return list<string> */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '{}') {
            return [];
        }

        return array_values(array_filter(explode(',', trim((string) $value, '{}'))));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return '{'.implode(',', (array) $value).'}';
    }
}
