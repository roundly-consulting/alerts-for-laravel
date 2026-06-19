<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Alert;

it('scopes to open and recovered alerts', function () {
    $healthCheck = createHealthCheckWithNotifiable();

    createAlertForHealthCheck($healthCheck, recovered: false);
    createAlertForHealthCheck($healthCheck, recovered: true);

    expect(Alert::query()->open()->count())->toBe(1)
        ->and(Alert::query()->recovered()->count())->toBe(1);
});
