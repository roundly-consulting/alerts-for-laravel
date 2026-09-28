<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Alerts\HealthManager;

final class PerformHealthChecks extends Command
{
    protected $signature = 'alerts:perform-health-checks';

    protected $description = 'Perform health checks and send notifications when necessary';

    public function handle(HealthManager $health): int
    {
        $queued = $health->runDue();

        $this->info("Queued {$queued} due health check(s).");

        return self::SUCCESS;
    }
}
