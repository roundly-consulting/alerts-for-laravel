<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Actions;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Alerts\AlertSilence;
use RoundlyConsulting\Alerts\Support\AlertSilenceModel;

/**
 * Opens a maintenance window: alert notifications for a check key, a tag, or `'*'`
 * are suppressed until `$until` (or until unmuted), optionally for one notifiable.
 */
final readonly class MuteAlertsAction
{
    public function execute(
        string $key,
        ?CarbonInterface $until = null,
        ?Model $notifiable = null,
        ?string $reason = null,
    ): AlertSilence {
        return AlertSilenceModel::class()::create([
            'key' => $key,
            'notifiable_type' => $notifiable?->getMorphClass(),
            'notifiable_id' => $notifiable?->getKey(),
            'reason' => $reason,
            'starts_at' => null,
            'ends_at' => $until,
        ]);
    }
}
