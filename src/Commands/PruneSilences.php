<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Alerts\HealthManager;
use RoundlyConsulting\Alerts\Support\AlertsConfig;

final class PruneSilences extends Command
{
    protected $signature = 'alerts:prune-silences {--days= : Delete silences that ended more than this many days ago}';

    protected $description = 'Delete alert silences that ended longer ago than the retention window';

    public function handle(HealthManager $health): int
    {
        $days = $this->option('days');

        if ($days === null || $days === '') {
            $days = null;
        } elseif ((is_int($days) || (is_string($days) && ctype_digit($days))) && (int) $days >= 1) {
            $days = (int) $days;
        } else {
            // A typo must never widen the prune: `--days=0` would delete every ended silence.
            $this->error('--days must be a whole number of days, at least 1.');

            return self::FAILURE;
        }

        $deleted = $health->silences()->prune($days);

        $days ??= AlertsConfig::silenceRetentionDays();

        $this->info("Pruned {$deleted} silence(s) that ended more than {$days} day(s) ago.");

        return self::SUCCESS;
    }
}
