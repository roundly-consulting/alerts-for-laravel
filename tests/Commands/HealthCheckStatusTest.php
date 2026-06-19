<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\Alerts\Commands\HealthCheckStatus;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;

it('reports success when there are no checks', function () {
    $this->artisan(HealthCheckStatus::class)
        ->expectsOutput('No health checks are scheduled.')
        ->assertSuccessful();
});

it('exits successfully when everything is healthy', function () {
    Health::check(ExampleHealthCheck::class);
    Team::create()->createHealthCheck('example_health_check', '* * * * *');

    $this->artisan(HealthCheckStatus::class)->assertSuccessful();
});

it('exits non-zero when a check is alertable', function () {
    Health::check(ExampleHealthCheck::class);

    $team = Team::create();
    $healthCheck = $team->createHealthCheck('example_health_check', '* * * * *');

    Alert::create([
        'notifiable_type' => $team->getMorphClass(),
        'notifiable_id' => $team->getKey(),
        'health_check_id' => $healthCheck->getKey(),
        'status' => Status::Failed,
        'triggered_at' => now(),
    ]);

    $this->artisan(HealthCheckStatus::class)->assertFailed();
});
