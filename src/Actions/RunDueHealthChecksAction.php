<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Actions;

use Illuminate\Contracts\Debug\ExceptionHandler;
use RoundlyConsulting\Alerts\Exceptions\InvalidCronExpression;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Support\HealthCheckModel;

/**
 * Queues a run for every scheduled check whose cron expression is due now. The
 * scheduler calls it every minute through `alerts:perform-health-checks`.
 *
 * Schedules are validated when saved, but a row written some other way (a factory,
 * a seeder, a hand-edited column) can still carry an expression the evaluator cannot
 * read. That row is skipped and reported to the exception handler — it must never stop
 * the rows after it from being dispatched.
 */
final readonly class RunDueHealthChecksAction
{
    public function __construct(
        private ExceptionHandler $exceptions,
    ) {}

    /**
     * @return int how many runs were queued
     */
    public function execute(): int
    {
        $queued = 0;

        HealthCheckModel::query()->whereNotNull('frequency')->each(function (HealthCheck $healthCheck) use (&$queued): void {
            try {
                $due = $healthCheck->isDue();
            } catch (InvalidCronExpression $e) {
                $this->exceptions->report(InvalidCronExpression::skipped($healthCheck, $e));

                return;
            }

            if ($due) {
                $healthCheck->dispatchHealthCheckJob();
                $queued++;
            }
        });

        return $queued;
    }
}
