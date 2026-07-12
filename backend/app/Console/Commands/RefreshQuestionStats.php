<?php

namespace App\Console\Commands;

use App\Services\Assessments\QuestionStats;
use Illuminate\Console\Command;

class RefreshQuestionStats extends Command
{
    protected $signature = 'assessments:refresh-stats';

    protected $description = 'Rebuild the item-analysis materialised view';

    public function handle(QuestionStats $stats): int
    {
        $stats->refresh();
        $this->info('question_stats refreshed.');

        return self::SUCCESS;
    }
}
