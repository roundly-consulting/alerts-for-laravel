<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\CheckResult;
use RoundlyConsulting\Alerts\Checks\ClosureCheck;
use RoundlyConsulting\Alerts\DataTransferObjects\ScheduleHealthCheckData;
use RoundlyConsulting\Alerts\Exceptions\InvalidHealthCheck;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Support\HealthCheckModel;

/**
 * Runs a check synchronously against a notifiable through the full alert pipeline.
 *
 * Given a Check, it runs through the notifiable's scheduled row for that check when
 * one exists, so a manual run shares the monitor's counters, history and alerts.
 * Otherwise it uses — seeding it on the first run — an ON-DEMAND row: frequency NULL,
 * so the scheduler never queues it, carrying the check's tags and, for an inline
 * check, its declarative options. Running a check now never starts monitoring it.
 *
 * Given one of the notifiable's rows, it runs that row as-is; a row scheduled against
 * another notifiable is refused.
 */
final readonly class RunHealthCheckNowAction
{
    public function __construct(
        private RunHealthCheckAction $runHealthCheck,
    ) {}

    public function execute(Model $notifiable, Check|HealthCheck $check): CheckResult
    {
        if ($check instanceof HealthCheck) {
            if (! $check->isScheduledFor($notifiable)) {
                throw InvalidHealthCheck::notScheduledFor($check, $notifiable);
            }

            return $this->runHealthCheck->execute($check);
        }

        return $this->runHealthCheck->execute($this->row($check, $notifiable), $check);
    }

    private function row(Check $check, Model $notifiable): HealthCheck
    {
        $rows = HealthCheckModel::query()
            ->where('notifiable_type', $notifiable->getMorphClass())
            ->where('notifiable_id', $notifiable->getKey())
            ->where('health_check', $check->key());

        $scheduled = (clone $rows)->whereNotNull('frequency')->oldest('id')->first();

        if ($scheduled !== null) {
            return $scheduled;
        }

        $definition = $this->definition($check);

        $row = $rows->whereNull('frequency')->firstOrCreate([], [
            'notifiable_type' => $notifiable->getMorphClass(),
            'notifiable_id' => $notifiable->getKey(),
            'health_check' => $check->key(),
            'frequency' => null,
            ...$definition,
        ]);

        // An inline check's on-demand row has no builder of its own: it mirrors the
        // definition, so a changed `define()->throttle()` or tag list takes effect on
        // the next run. A class check's row keeps whatever it was seeded (or edited) with.
        if ($check instanceof ClosureCheck && ! $row->wasRecentlyCreated && $row->fill($definition)->isDirty()) {
            $row->save();
        }

        return $row;
    }

    /**
     * The columns an on-demand row takes from the check: its tags and, for an inline
     * check, the throttle and monitor options it was defined with.
     *
     * @return array<string, mixed>
     */
    private function definition(Check $check): array
    {
        $tags = $check->tags();

        $data = $check instanceof ClosureCheck
            ? $check->scheduleDefaults()
            : new ScheduleHealthCheckData(check: $check->key());

        return [
            'max_attempts' => $data->maxAttempts,
            'decay_minutes' => $data->decayMinutes,
            'tags' => $tags === [] ? null : $tags,
            'meta' => $data->metaWithOptions(),
        ];
    }
}
