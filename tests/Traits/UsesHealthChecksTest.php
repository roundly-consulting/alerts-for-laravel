<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\MorphMany;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;

it('stores alertable in database', function () {
    Health::check(ExampleHealthCheck::class);

    /** @var Team $team */
    $team = Team::create();

    $healthCheck = $team->createHealthCheck(
        healthCheckKey: 'example_health_check',
        frequency: '*/5 * * * *',
        maxAttempts: 2,
        decayMinutes: 5,
    );

    expect($healthCheck->healthCheck())->toBeInstanceOf(ExampleHealthCheck::class);

    $this->assertDatabaseHas('health_checks', [
        'notifiable_type' => $team->getMorphClass(),
        'notifiable_id' => $team->getKey(),
        'health_check' => 'example_health_check',
        'frequency' => '*/5 * * * *',
        'max_attempts' => 2,
        'decay_minutes' => 5,
    ]);
});

it('has health checks relationship', function () {
    /** @var Team $team */
    $team = Team::create();

    expect($team->healthChecks())->toBeInstanceOf(MorphMany::class);
});

it('has alerts relationship', function () {
    /** @var Team $team */
    $team = Team::create();

    expect($team->alerts())->toBeInstanceOf(MorphMany::class);
});
