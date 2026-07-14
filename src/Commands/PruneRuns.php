<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Alerts\Support\HealthCheckRunModel;

final class PruneRuns extends Command
{
    protected $signature = 'alerts:prune-runs {--days= : Delete runs older than this many days}';

    protected $description = 'Delete health check run history older than the retention window';

    public function handle(): int
    {
        $days = $this->option('days');
        $days = is_numeric($days) ? (int) $days : (int) config('alerts.history.retention_days', 30);

        $deleted = HealthCheckRunModel::query()
            ->where('ran_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Pruned {$deleted} health check run(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
