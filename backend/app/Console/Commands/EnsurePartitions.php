<?php

namespace App\Console\Commands;

use App\Support\Partitions;
use Illuminate\Console\Command;

/**
 * Keeps the partition runway ahead of the clock.
 *
 * Runs daily. If it stops running, inserts keep working until the runway
 * expires and then fail *all at once* — so alert on a short runway, not on a
 * failed insert.
 */
class EnsurePartitions extends Command
{
    protected $signature = 'partitions:ensure {--months=}';

    protected $description = 'Create the upcoming monthly partitions';

    public function handle(): int
    {
        $months = (int) ($this->option('months') ?? Partitions::LOOKAHEAD_MONTHS);
        $created = Partitions::ensure($months);

        $created === []
            ? $this->info('Partition runway already covers the next '.$months.' month(s).')
            : $this->info('Created: '.implode(', ', $created));

        return self::SUCCESS;
    }
}
