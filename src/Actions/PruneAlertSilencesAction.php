<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Actions;

use RoundlyConsulting\Alerts\Exceptions\InvalidRetention;
use RoundlyConsulting\Alerts\Support\AlertsConfig;
use RoundlyConsulting\Alerts\Support\AlertSilenceModel;

/**
 * Deletes silences that ended longer ago than the retention window. An open-ended silence
 * (`ends_at` null) and a window still running or yet to come are never touched.
 */
final readonly class PruneAlertSilencesAction
{
    /**
     * @param  int|null  $days  defaults to `alerts.silence-retention` (a junk or sub-1 value throws)
     * @return int how many silences were deleted
     *
     * @throws InvalidRetention for `$days` below 1, the same floor the config has
     */
    public function execute(?int $days = null): int
    {
        if ($days !== null && $days < 1) {
            throw InvalidRetention::silenceDays($days);
        }

        $days ??= AlertsConfig::silenceRetentionDays();

        return (int) AlertSilenceModel::query()
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', now()->subDays($days))
            ->delete();
    }
}
