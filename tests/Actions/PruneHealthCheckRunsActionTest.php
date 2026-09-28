<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Actions\PruneHealthCheckRunsAction;
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
