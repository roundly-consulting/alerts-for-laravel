<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Alerts\Actions\RunDueHealthChecksAction;
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
