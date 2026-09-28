<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Actions;

use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Support\HealthCheckModel;

/**
 * Queues a run for every scheduled check whose cron expression is due now. The
 * scheduler calls it every minute through `alerts:perform-health-checks`.
 */
final readonly class RunDueHealthChecksAction
{
    /**
     * @return int how many runs were queued
     */
    public function execute(): int
    {
        $queued = 0;

        HealthCheckModel::query()->each(function (HealthCheck $healthCheck) use (&$queued): void {
            if ($healthCheck->isDue()) {
                $healthCheck->dispatchHealthCheckJob();
                $queued++;
            }
        });

        return $queued;
    }
}
