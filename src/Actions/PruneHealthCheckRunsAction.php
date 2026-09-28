<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Actions;

use RoundlyConsulting\Alerts\Support\HealthCheckRunModel;

/**
 * Deletes run history older than the retention window.
 */
final readonly class PruneHealthCheckRunsAction
{
    /**
     * @param  int|null  $days  defaults to `alerts.history.retention_days`
     * @return int how many runs were deleted
     */
    public function execute(?int $days = null): int
    {
        $days ??= (int) config('alerts.history.retention_days', 30);

        return (int) HealthCheckRunModel::query()
            ->where('ran_at', '<', now()->subDays($days))
            ->delete();
    }
}
