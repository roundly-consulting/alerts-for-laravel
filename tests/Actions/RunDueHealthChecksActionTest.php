<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Alerts\Actions\RunDueHealthChecksAction;
use RoundlyConsulting\Alerts\AlertsServiceProvider;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Jobs\HealthCheckJob;

it('queues only the due checks and returns how many', function (): void {
    Queue::fake();
    Carbon::setTestNow('2023-03-22 14:00:00');

    $due = createHealthCheckWithNotifiable(frequency: '@hourly');
    createHealthCheckWithNotifiable(frequency: '30 * * * *');

    expect(app(RunDueHealthChecksAction::class)->execute())->toBe(1);

    Queue::assertPushed(HealthCheckJob::class, fn (HealthCheckJob $job): bool => $job->healthCheck->is($due));
    Queue::assertPushed(HealthCheckJob::class, 1);

    Carbon::setTestNow();
});

it('skips and reports a row with an invalid cron instead of stopping the rest', function (): void {
    Queue::fake();
    Illuminate\Support\Facades\Exceptions::fake();
    Carbon::setTestNow('2023-03-22 14:00:00');

    $first = createHealthCheckWithNotifiable(frequency: '* * * * *');
    $bad = createHealthCheckWithNotifiable(frequency: '0 9 * * FUNDAY');
    $last = createHealthCheckWithNotifiable(frequency: '* * * * *');

    expect(app(RunDueHealthChecksAction::class)->execute())->toBe(2);

    Queue::assertPushed(HealthCheckJob::class, 2);
    Queue::assertPushed(HealthCheckJob::class, fn (HealthCheckJob $job): bool => $job->healthCheck->is($last));
    Illuminate\Support\Facades\Exceptions::assertReported(
        fn (RoundlyConsulting\Alerts\Exceptions\InvalidCronExpression $e): bool => str_contains($e->getMessage(), '#'.$bad->getKey()),
    );

    Carbon::setTestNow();
});

/**
 * Drive the perform command the way Laravel's scheduler does at the given cadence: every
 * minute of the walk, run the due checks when the scheduler's event is due. Returns how
 * many runs each row got.
 *
 * @param  list<HealthCheck>  $rows
 * @return list<int>
 */
function walkSchedulerTicks(string $cadence, string $from, int $minutes, array $rows): array
{
    config()->set('alerts.schedule.frequency', $cadence);

    $schedule = new Schedule;
    (new AlertsServiceProvider(app()))->scheduleCommand($schedule);

    /** @var Event $event */
    $event = collect($schedule->events())
        ->first(fn (Event $event): bool => str_contains($event->command ?? '', 'alerts:perform-health-checks'));

    Queue::fake();
    $start = Carbon::parse($from);

    for ($minute = 0; $minute < $minutes; $minute++) {
        Carbon::setTestNow($start->copy()->addMinutes($minute));

        if ($event->isDue(app())) {
            app(RunDueHealthChecksAction::class)->execute();
        }
    }

    Carbon::setTestNow();

    return array_map(
        fn (HealthCheck $row): int => Queue::pushed(HealthCheckJob::class, fn (HealthCheckJob $job): bool => $job->healthCheck->is($row))->count(),
        $rows,
    );
}

it('runs a check whose minute falls between two five-minute ticks', function (): void {
    $offTick = createHealthCheckWithNotifiable(frequency: '7 * * * *');
    $onTick = createHealthCheckWithNotifiable(frequency: '*/5 * * * *');

    expect(walkSchedulerTicks('everyFiveMinutes', '2026-10-05 00:00:00', 24 * 60, [$offTick, $onTick]))
        ->toBe([24, 288]);
});

it('runs a daily check when the scheduler only ticks on odd hours', function (): void {
    $daily = createHealthCheckWithNotifiable(frequency: '@daily');

    expect(walkSchedulerTicks('everyOddHour', '2026-10-04 23:00:00', 24 * 60, [$daily]))->toBe([1]);
});

it('keeps every-minute ticks at one run per due minute', function (): void {
    $hourly = createHealthCheckWithNotifiable(frequency: '@hourly');
    $everyMinute = createHealthCheckWithNotifiable(frequency: '* * * * *');

    expect(walkSchedulerTicks('everyMinute', '2026-10-05 00:00:00', 3 * 60, [$hourly, $everyMinute]))
        ->toBe([3, 180]);
});

it('looks at the current minute only when the remembered tick is not earlier', function (): void {
    Queue::fake();
    $hourly = createHealthCheckWithNotifiable(frequency: '@hourly');

    Carbon::setTestNow('2026-10-05 15:30:00');
    app(RunDueHealthChecksAction::class)->execute();

    // The clock went back: no window from a tick in the future, just this minute.
    Carbon::setTestNow('2026-10-05 14:30:00');
    expect(app(RunDueHealthChecksAction::class)->execute())->toBe(0);

    Carbon::setTestNow('2026-10-05 15:00:00');
    expect(app(RunDueHealthChecksAction::class)->execute())->toBe(1);

    // A second run in the same minute evaluates that minute again, as before.
    expect(app(RunDueHealthChecksAction::class)->execute())->toBe(1);

    Queue::assertPushed(HealthCheckJob::class, fn (HealthCheckJob $job): bool => $job->healthCheck->is($hourly));

    Carbon::setTestNow();
});
