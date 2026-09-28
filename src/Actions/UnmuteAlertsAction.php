<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Alerts\Support\AlertSilenceModel;

/**
 * Lifts the silences on a key: the global ones, or — given a notifiable — only the
 * ones scoped to it, leaving global silences in force.
 */
final readonly class UnmuteAlertsAction
{
    /**
     * @return int how many silences were lifted
     */
    public function execute(string $key, ?Model $notifiable = null): int
    {
        $query = AlertSilenceModel::query()->where('key', $key);

        if ($notifiable !== null) {
            $query->where('notifiable_type', $notifiable->getMorphClass())
                ->where('notifiable_id', $notifiable->getKey());
        } else {
            $query->whereNull('notifiable_id');
        }

        return (int) $query->delete();
    }
}
