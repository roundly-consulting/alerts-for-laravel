<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Alerts\Commands\PerformHealthChecks;
use RoundlyConsulting\Alerts\Jobs\HealthCheckJob;

it('does nothing when no health check is defined in database', function () {
    Queue::fake();

    $this->artisan(PerformHealthChecks::class)
        ->assertSuccessful()
        ->expectsOutput('All health checks queued.');

    Queue::assertNothingPushed();
});

it('does not queue health checks that are not due', function () {
    Queue::fake();

    createHealthCheckWithNotifiable(frequency: '@hourly');

    Carbon::setTestNow('2023-03-22 14:25:00');

    $this->artisan(PerformHealthChecks::class)
        ->assertSuccessful()
        ->expectsOutput('All health checks queued.');

    Queue::assertNothingPushed();
});

it('it queues health check when its due', function () {
    Queue::fake();

    $healthCheck = createHealthCheckWithNotifiable(frequency: '@hourly');

    Carbon::setTestNow('2023-03-22 14:00:00');

    $this->artisan(PerformHealthChecks::class)
        ->assertSuccessful()
        ->expectsOutput('All health checks queued.');

    Queue::assertPushed(HealthCheckJob::class, function (HealthCheckJob $job) use ($healthCheck) {
        return $job->healthCheck->is($healthCheck);
    });
});
