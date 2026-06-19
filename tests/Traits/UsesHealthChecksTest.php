<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\MorphMany;
use RoundlyConsulting\Alerts\DataTransferObjects\ScheduleHealthCheckData;
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

it('fluently attaches a scheduled check with a preset frequency', function () {
    /** @var Team $team */
    $team = Team::create();

    $healthCheck = $team->monitorCheck(ExampleHealthCheck::class)
        ->hourly()
        ->throttle(maxAttempts: 3, decayMinutes: 10)
        ->meta(['connection' => 'mysql'])
        ->save();

    expect($healthCheck->health_check)->toBe('example_health_check')
        ->and($healthCheck->frequency)->toBe('@hourly')
        ->and($healthCheck->max_attempts)->toBe(3)
        ->and($healthCheck->decay_minutes)->toBe(10)
        ->and($healthCheck->meta)->toBe(['connection' => 'mysql']);
});

it('maps every fluent frequency preset to cron', function () {
    /** @var Team $team */
    $team = Team::create();

    expect($team->monitorCheck(ExampleHealthCheck::class)->everyFiveMinutes()->save()->frequency)->toBe('*/5 * * * *')
        ->and($team->monitorCheck(ExampleHealthCheck::class)->everyTenMinutes()->save()->frequency)->toBe('*/10 * * * *')
        ->and($team->monitorCheck(ExampleHealthCheck::class)->everyFifteenMinutes()->save()->frequency)->toBe('*/15 * * * *')
        ->and($team->monitorCheck(ExampleHealthCheck::class)->everyThirtyMinutes()->save()->frequency)->toBe('*/30 * * * *')
        ->and($team->monitorCheck(ExampleHealthCheck::class)->everyMinute()->save()->frequency)->toBe('* * * * *')
        ->and($team->monitorCheck(ExampleHealthCheck::class)->daily()->save()->frequency)->toBe('@daily')
        ->and($team->monitorCheck(ExampleHealthCheck::class)->weekly()->save()->frequency)->toBe('@weekly')
        ->and($team->monitorCheck(ExampleHealthCheck::class)->monthly()->save()->frequency)->toBe('@monthly')
        ->and($team->monitorCheck(ExampleHealthCheck::class)->frequency('hourly')->save()->frequency)->toBe('@hourly')
        ->and($team->monitorCheck(ExampleHealthCheck::class)->cron('15 3 * * *')->save()->frequency)->toBe('15 3 * * *');
});

it('attaches a scheduled check from a DTO', function () {
    /** @var Team $team */
    $team = Team::create();

    $healthCheck = $team->monitor(new ScheduleHealthCheckData(
        check: ExampleHealthCheck::class,
        frequency: 'hourly',
        maxAttempts: 2,
        decayMinutes: 5,
    ));

    expect($healthCheck->health_check)->toBe('example_health_check')
        ->and($healthCheck->frequency)->toBe('@hourly')
        ->and($healthCheck->max_attempts)->toBe(2);
});
