<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Actions;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Carbon;
use RoundlyConsulting\Alerts\Exceptions\InvalidCronExpression;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Support\HealthCheckModel;

/**
 * Queues a run for every scheduled check whose cron expression is due now. The
 * scheduler calls it through `alerts:perform-health-checks` at `alerts.schedule.frequency`.
 *
 * That cadence may be coarser than a check's cron (`everyFiveMinutes` against `7 * * * *`),
 * so each run remembers its minute in the cache and the next one queues every check that
 * came due at any minute since — once, however many of its minutes were stepped over. A
 * first run, or one in the same minute as (or before) the remembered one, looks at the
 * current minute only.
 *
 * Schedules are validated when saved, but a row written some other way (a factory,
 * a seeder, a hand-edited column) can still carry an expression the evaluator cannot
 * read. That row is skipped and reported to the exception handler — it must never stop
 * the rows after it from being dispatched.
 */
final readonly class RunDueHealthChecksAction
{
    public const string LAST_TICK = 'alerts:due-checks:last-tick';

    public function __construct(
        private ExceptionHandler $exceptions,
        private Repository $cache,
    ) {}

    /**
     * @return int how many runs were queued
     */
    public function execute(): int
    {
        $queued = 0;
        $now = now()->startOfMinute();
        $since = $this->previousTick($now);

        HealthCheckModel::query()->whereNotNull('frequency')->each(function (HealthCheck $healthCheck) use (&$queued, $since): void {
            try {
                $due = $healthCheck->isDue($since);
            } catch (InvalidCronExpression $e) {
                $this->exceptions->report(InvalidCronExpression::skipped($healthCheck, $e));

                return;
            }

            if ($due) {
                $healthCheck->dispatchHealthCheckJob();
                $queued++;
            }
        });

        $this->cache->forever(self::LAST_TICK, $now->getTimestamp());

        return $queued;
    }

    private function previousTick(CarbonInterface $now): ?CarbonInterface
    {
        $previous = $this->cache->get(self::LAST_TICK);

        if (! is_int($previous) || $previous >= $now->getTimestamp()) {
            return null;
        }

        return Carbon::createFromTimestamp($previous, $now->getTimezone());
    }
}
