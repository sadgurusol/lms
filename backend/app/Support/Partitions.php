<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Monthly range partitions for the two high-volume tables.
 *
 * Postgres does not create partitions on demand. When the runway runs out,
 * every INSERT fails with "no partition of relation found for row" — audit
 * logging and activity ingest stop dead, silently, a year after launch. The
 * migrations seed twelve months; this keeps the runway topped up.
 *
 * A DEFAULT partition would soften the failure, but it makes attaching the
 * next real partition take an ACCESS EXCLUSIVE lock while it scans the default
 * for rows that belong in it. A monitored runway is the better trade.
 */
final class Partitions
{
    /** table => partition key column */
    public const TABLES = [
        'audit_logs' => 'created_at',
        'activity_events' => 'occurred_at',
    ];

    /** How many months ahead to keep provisioned. */
    public const LOOKAHEAD_MONTHS = 6;

    /** @return list<string> the partitions created */
    public static function ensure(int $lookaheadMonths = self::LOOKAHEAD_MONTHS): array
    {
        $created = [];
        $month = now()->startOfMonth();

        foreach (array_keys(self::TABLES) as $table) {
            for ($i = 0; $i <= $lookaheadMonths; $i++) {
                $name = self::create($table, $month->copy()->addMonths($i));

                if ($name !== null) {
                    $created[] = $name;
                }
            }
        }

        return $created;
    }

    /** @return list<string> the partitions dropped */
    public static function prune(string $table, Carbon $before): array
    {
        $dropped = [];

        foreach (self::partitionsFor($table) as $partition) {
            $month = self::monthOf($table, $partition);

            if ($month !== null && $month->lessThan($before->copy()->startOfMonth())) {
                // DETACH first: the drop then takes no lock on the parent, so a
                // nightly prune cannot stall ingest.
                DB::statement("ALTER TABLE {$table} DETACH PARTITION {$partition}");
                DB::statement("DROP TABLE {$partition}");

                $dropped[] = $partition;
            }
        }

        return $dropped;
    }

    /** @return list<string> */
    public static function partitionsFor(string $table): array
    {
        return array_map(
            fn (object $row) => $row->relname,
            DB::select(<<<'SQL'
                SELECT c.relname
                  FROM pg_inherits i
                  JOIN pg_class c ON c.oid = i.inhrelid
                  JOIN pg_class p ON p.oid = i.inhparent
                 WHERE p.relname = ?
                 ORDER BY c.relname
            SQL, [$table]),
        );
    }

    private static function create(string $table, Carbon $month): ?string
    {
        $name = $table.'_'.$month->format('Y_m');

        if (in_array($name, self::partitionsFor($table), true)) {
            return null;
        }

        $from = $month->format('Y-m-d');
        $to = $month->copy()->addMonth()->format('Y-m-d');

        DB::statement("CREATE TABLE IF NOT EXISTS {$name}
            PARTITION OF {$table} FOR VALUES FROM ('{$from}') TO ('{$to}')");

        return $name;
    }

    private static function monthOf(string $table, string $partition): ?Carbon
    {
        $suffix = str_replace($table.'_', '', $partition);

        return preg_match('/^\d{4}_\d{2}$/', $suffix) === 1
            ? Carbon::createFromFormat('Y_m_d', $suffix.'_01')->startOfMonth()
            : null;
    }
}
