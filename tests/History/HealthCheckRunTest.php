<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Actions\RunHealthCheckAction;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\HealthCheckRun;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;
use RoundlyConsulting\Alerts\Tests\Models\User;

it('records exactly one immutable run with a latency for each executed check', function () {
    $healthCheck = createHealthCheckWithNotifiable();
    User::create(['email' => 'a@b.com']);

    app(RunHealthCheckAction::class)->execute($healthCheck);

    $run = $healthCheck->latestRun();

    expect($healthCheck->runs()->count())->toBe(1)
        ->and($run)->toBeInstanceOf(HealthCheckRun::class)
        ->and($run->status)->toBe(Status::Ok)
        ->and($run->duration_ms)->toBeGreaterThanOrEqual(0);
});

it('records a run even for a skipped result', function () {
    ExampleHealthCheck::$status = Status::Skipped;

    $healthCheck = createHealthCheckWithNotifiable();

    app(RunHealthCheckAction::class)->execute($healthCheck);

    expect($healthCheck->latestRun()?->status)->toBe(Status::Skipped);
});

it('writes no run when history is disabled', function () {
    config()->set('alerts.history.enabled', false);

    $healthCheck = createHealthCheckWithNotifiable();
    User::create(['email' => 'a@b.com']);

    app(RunHealthCheckAction::class)->execute($healthCheck);

    expect($healthCheck->runs()->count())->toBe(0);
});

it('computes uptime percentage across mixed runs', function () {
    $team = Team::create();
    $healthCheck = createHealthCheckWithNotifiable($team);

    HealthCheckRun::factory()->count(3)->create([
        'health_check_id' => $healthCheck->getKey(),
        'status' => Status::Ok,
    ]);
    HealthCheckRun::factory()->create([
        'health_check_id' => $healthCheck->getKey(),
        'status' => Status::Failed,
    ]);

    expect($healthCheck->uptimePercentage())->toBe(75.0);
});

it('returns full uptime for empty history', function () {
    $healthCheck = createHealthCheckWithNotifiable();

    expect($healthCheck->uptimePercentage())->toBe(100.0);
});

it('computes the p95 latency for a known set', function () {
    $healthCheck = createHealthCheckWithNotifiable();

    foreach ([10, 20, 30, 40, 50, 60, 70, 80, 90, 100] as $ms) {
        HealthCheckRun::factory()->create([
            'health_check_id' => $healthCheck->getKey(),
            'duration_ms' => $ms,
        ]);
    }

    expect($healthCheck->p95LatencyMs())->toBe(100);
});

it('returns zero p95 latency on empty history', function () {
    $healthCheck = createHealthCheckWithNotifiable();

    expect($healthCheck->p95LatencyMs())->toBe(0);
});

it('honours a since window for uptime and p95', function () {
    $healthCheck = createHealthCheckWithNotifiable();

    HealthCheckRun::factory()->create([
        'health_check_id' => $healthCheck->getKey(),
        'status' => Status::Failed,
        'duration_ms' => 999,
        'ran_at' => now()->subWeek(),
    ]);
    HealthCheckRun::factory()->create([
        'health_check_id' => $healthCheck->getKey(),
        'status' => Status::Ok,
        'duration_ms' => 10,
        'ran_at' => now(),
    ]);

    expect($healthCheck->uptimePercentage(now()->subDay()))->toBe(100.0)
        ->and($healthCheck->p95LatencyMs(now()->subDay()))->toBe(10);
});
