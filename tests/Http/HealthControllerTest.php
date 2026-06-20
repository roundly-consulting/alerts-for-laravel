<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;

beforeEach(function (): void {
    Health::routes('health');
});

it('returns 200 when healthy', function () {
    $this->getJson('health')
        ->assertOk()
        ->assertJsonPath('status', 'ok');
});

it('returns 503 when a check is alertable', function () {
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

    $this->getJson('health')
        ->assertStatus(503)
        ->assertJsonPath('status', 'failed');
});

it('filters the endpoint by tag', function () {
    Health::check(ExampleHealthCheck::class);

    $team = Team::create();
    $healthCheck = $team->createHealthCheck('example_health_check', '* * * * *', tags: ['db']);

    Alert::create([
        'notifiable_type' => $team->getMorphClass(),
        'notifiable_id' => $team->getKey(),
        'health_check_id' => $healthCheck->getKey(),
        'status' => Status::Failed,
        'triggered_at' => now(),
    ]);

    $this->getJson('health?tag=db')
        ->assertStatus(503)
        ->assertJsonPath('checks.0.key', 'example_health_check');

    $this->getJson('health?tag=cache')
        ->assertOk()
        ->assertJsonPath('checks', []);
});

it('registers a named route', function () {
    $route = Health::routes('healthz');

    expect($route->getName())->toBe('alerts.health')
        ->and($route->uri())->toBe('healthz');
});
