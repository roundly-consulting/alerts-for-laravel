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
