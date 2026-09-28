<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\Exceptions\InvalidHealthCheck;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Support\HealthCheckModel;

/**
 * Stops monitoring: soft-deletes a notifiable's schedules for a check, or one given
 * row. A row scheduled against a different notifiable is refused — the scope is the
 * security boundary, so a caller holding one owner cannot remove another's monitor.
 */
final readonly class UnscheduleHealthCheckAction
{
    public function execute(Model $notifiable, string|Check|HealthCheck $check): int
    {
        if ($check instanceof HealthCheck) {
            if (! $check->isScheduledFor($notifiable)) {
                throw InvalidHealthCheck::notScheduledFor($check, $notifiable);
            }

            return $check->delete() === true ? 1 : 0;
        }

        return (int) HealthCheckModel::query()
            ->where('notifiable_type', $notifiable->getMorphClass())
            ->where('notifiable_id', $notifiable->getKey())
            ->where('health_check', $this->key($check))
            ->delete();
    }

    private function key(string|Check $check): string
    {
        if ($check instanceof Check) {
            return $check->key();
        }

        return is_subclass_of($check, Check::class) ? (new $check)->key() : $check;
    }
}
