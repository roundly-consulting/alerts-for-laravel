<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Support\HealthCheckModel;

final class PerformHealthChecks extends Command
{
    protected $signature = 'alerts:perform-health-checks';

    protected $description = 'Perform health checks and send notifications when necessary';

    public function handle(): int
    {
        HealthCheckModel::query()->each(function (HealthCheck $healthCheck): void {
            if ($healthCheck->isDue()) {
                $healthCheck->dispatchHealthCheckJob();
            }
        });

        $this->info('All health checks queued.');

        return self::SUCCESS;
    }
}
