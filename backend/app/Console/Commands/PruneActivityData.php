<?php

namespace App\Console\Commands;

use App\Support\Partitions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Drops partitions past their retention window, and the idempotency keys that
 * outlived any client's offline outbox.
 */
class PruneActivityData extends Command
{
    protected $signature = 'activity:prune {--dry-run}';

    protected $description = 'Drop partitions and idempotency keys past retention';

    public function handle(): int
    {
        $plan = [
            'audit_logs' => now()->subMonths(config('retention.audit_logs_months')),
            'activity_events' => now()->subMonths(config('retention.activity_events_months')),
        ];

        foreach ($plan as $table => $before) {
            if ($this->option('dry-run')) {
                $this->line("[dry-run] would prune {$table} before {$before->toDateString()}");

                continue;
            }

            $dropped = Partitions::prune($table, $before);
            $this->info("{$table}: dropped ".count($dropped).' partition(s).');
        }

        if (! $this->option('dry-run')) {
            $keys = DB::table('activity_event_keys')
                ->where('first_seen_at', '<', now()->subDays(config('retention.activity_event_keys_days')))
                ->delete();

            $this->info("activity_event_keys: pruned {$keys} row(s).");
        }

        return self::SUCCESS;
    }
}
