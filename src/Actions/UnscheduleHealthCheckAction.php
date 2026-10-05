<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Actions;

use Closure;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\Exceptions\InvalidHealthCheck;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\HealthManager;
use RoundlyConsulting\Alerts\Support\AlertModel;
use RoundlyConsulting\Alerts\Support\HealthCheckModel;

/**
 * Stops monitoring: soft-deletes a notifiable's schedules for a check, or one given
 * row. A row scheduled against a different notifiable is refused — the scope is the
 * security boundary, so a caller holding one owner cannot remove another's monitor.
 *
 * An alert still open on a removed row is closed with it: no run will ever reach it
 * again, and the report no longer lists the row, so it would stay open forever. Closing
 * is not a recovery — no HealthCheckRecovered event is dispatched.
 */
final readonly class UnscheduleHealthCheckAction
{
    public function __construct(
        private HealthManager $health,
    ) {}

    public function execute(Model $notifiable, string|Check|HealthCheck $check): int
    {
        if ($check instanceof HealthCheck) {
            if (! $check->isScheduledFor($notifiable)) {
                throw InvalidHealthCheck::notScheduledFor($check, $notifiable);
            }

            return $this->stop([$check->getKey()], fn (): int => $check->delete() === true ? 1 : 0);
        }

        $rows = HealthCheckModel::query()
            ->where('notifiable_type', $notifiable->getMorphClass())
            ->where('notifiable_id', $notifiable->getKey())
            ->where('health_check', $this->health->keyFor($check));

        $ids = (clone $rows)->pluck(HealthCheckModel::new()->getKeyName())->all();

        return $this->stop($ids, fn (): int => (int) $rows->delete());
    }

    /**
     * @param  array<int, mixed>  $ids  the rows being removed
     * @param  Closure(): int  $delete
     */
    private function stop(array $ids, Closure $delete): int
    {
        return HealthCheckModel::query()->getConnection()->transaction(function () use ($ids, $delete): int {
            $deleted = $delete();

            if ($ids !== []) {
                AlertModel::query()
                    ->whereIn('health_check_id', $ids)
                    ->whereNull('recovered_at')
                    ->update([
                        'recovered_at' => now(),
                        'escalation_level' => 0,
                        'open_slot' => null,
                    ]);
            }

            return $deleted;
        });
    }
}
