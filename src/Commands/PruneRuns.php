<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Alerts\HealthManager;
use RoundlyConsulting\Alerts\Support\AlertsConfig;

final class PruneRuns extends Command
{
    protected $signature = 'alerts:prune-runs {--days= : Delete runs older than this many days}';

    protected $description = 'Delete health check run history older than the retention window';

    public function handle(HealthManager $health): int
    {
        $days = $this->option('days');
        $days = is_numeric($days) ? (int) $days : null;

        $deleted = $health->prune($days);

        $days ??= AlertsConfig::retentionDays();

        $this->info("Pruned {$deleted} health check run(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
