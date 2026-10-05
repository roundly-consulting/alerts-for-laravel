<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Actions\PruneHealthCheckRunsAction;
use RoundlyConsulting\Alerts\Exceptions\InvalidRetention;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\HealthCheckRun;

it('deletes runs older than the given or configured retention', function (): void {
    config()->set('alerts.history.retention_days', 3);
    $row = createHealthCheckWithNotifiable();

    foreach ([9, 5, 1] as $daysAgo) {
        HealthCheckRun::factory()->create(['health_check_id' => $row->getKey(), 'ran_at' => now()->subDays($daysAgo)]);
    }

    $action = app(PruneHealthCheckRunsAction::class);

    expect($action->execute(7))->toBe(1)
        ->and($action->execute())->toBe(1)
        ->and(HealthCheckRun::count())->toBe(1);
});

it('refuses a retention below one day instead of deleting the whole history', function (int $days): void {
    $row = createHealthCheckWithNotifiable();
    HealthCheckRun::factory()->create(['health_check_id' => $row->getKey(), 'ran_at' => now()->subMinute()]);

    expect(fn () => app(PruneHealthCheckRunsAction::class)->execute($days))
        ->toThrow(InvalidRetention::class, 'at least 1 day');

    expect(fn () => Health::prune($days))->toThrow(InvalidRetention::class)
        ->and(HealthCheckRun::count())->toBe(1);
})->with(['zero' => 0, 'negative' => -1]);
