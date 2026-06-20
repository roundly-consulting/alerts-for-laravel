<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\HealthCheckRun;
use RoundlyConsulting\Alerts\Tests\Models\Team;

it('belongs to its health check', function () {
    $team = Team::create();
    $healthCheck = createHealthCheckWithNotifiable($team);

    $run = HealthCheckRun::factory()->create([
        'health_check_id' => $healthCheck->getKey(),
    ]);

    expect($run->healthCheck)->toBeInstanceOf(HealthCheck::class)
        ->and($run->healthCheck->is($healthCheck))->toBeTrue();
});

it('returns the most recent run via latestRun', function () {
    $team = Team::create();
    $healthCheck = createHealthCheckWithNotifiable($team);

    HealthCheckRun::factory()->create([
        'health_check_id' => $healthCheck->getKey(),
        'ran_at' => now()->subHour(),
        'message' => 'old',
    ]);
    HealthCheckRun::factory()->create([
        'health_check_id' => $healthCheck->getKey(),
        'ran_at' => now(),
        'message' => 'new',
    ]);

    expect($healthCheck->latestRun()?->message)->toBe('new');
});
