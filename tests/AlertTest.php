<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;

it('has notifiable relationship', function () {
    $healthCheck = createHealthCheckWithNotifiable();
    $alert = createAlertForHealthCheck($healthCheck);

    expect($alert->notifiable())
        ->toBeInstanceOf(MorphTo::class)
        ->and($alert->notifiable)
        ->toBeInstanceOf(Team::class);
});

it('has health check relationship', function () {
    $healthCheck = createHealthCheckWithNotifiable();
    $alert = createAlertForHealthCheck($healthCheck);

    expect($alert->healthCheck())
        ->toBeInstanceOf(BelongsTo::class)
        ->and($alert->healthCheck)
        ->toBeInstanceOf(HealthCheck::class)
        ->getKey()->tobe($healthCheck->getKey());
});
