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

        if ($days === null || $days === '') {
            $days = null;
        } elseif ((is_int($days) || (is_string($days) && ctype_digit($days))) && (int) $days >= 1) {
            $days = (int) $days;
        } else {
            // A typo must never widen the prune: `--days=0` used to delete the whole history.
            $this->error('--days must be a whole number of days, at least 1.');

            return self::FAILURE;
        }

        $deleted = $health->prune($days);

        $days ??= AlertsConfig::retentionDays();

        $this->info("Pruned {$deleted} health check run(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
