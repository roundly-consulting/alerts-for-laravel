<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\CheckResult;
use RoundlyConsulting\Alerts\Checks\ClosureCheck;
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

        return $this->runHealthCheck->execute($this->row($check, $notifiable));
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

        [$tags, $meta] = $this->seedFor($check);

        return $rows->whereNull('frequency')->firstOrCreate([], [
            'notifiable_type' => $notifiable->getMorphClass(),
            'notifiable_id' => $notifiable->getKey(),
            'health_check' => $check->key(),
            'frequency' => null,
            'max_attempts' => 1,
            'decay_minutes' => 1,
            'tags' => $tags === [] ? null : $tags,
            'meta' => $meta,
        ]);
    }

    /**
     * Seed tags + declarative meta for an ad-hoc row from an inline closure check's
     * pending options, so an ad-hoc run honours failAfter/timeout/etc.
     *
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    private function seedFor(Check $check): array
    {
        if ($check instanceof ClosureCheck) {
            return [$check->tags(), $check->pendingMeta()];
        }

        return [$check->tags(), []];
    }
}
