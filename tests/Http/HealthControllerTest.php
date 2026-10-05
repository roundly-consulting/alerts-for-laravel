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

function failingTenantCheck(): void
{
    Health::check(ExampleHealthCheck::class);

    $team = Team::create();
    $healthCheck = $team->createHealthCheck('example_health_check', '* * * * *', tags: ['tenant-a']);

    Alert::create([
        'notifiable_type' => $team->getMorphClass(),
        'notifiable_id' => $team->getKey(),
        'health_check_id' => $healthCheck->getKey(),
        'status' => Status::Failed,
        'message' => 'SQLSTATE[HY000] [2002] (Connection: tenant_a, Host: 10.0.3.4, Database: tenant_a_prod)',
        'triggered_at' => now(),
    ]);
}

it('renders only key, name, status, uptime and latency by default', function () {
    failingTenantCheck();

    $check = $this->getJson('health')
        ->assertStatus(503)
        ->json('checks.0');

    expect(array_keys($check))->toBe(['key', 'name', 'status', 'uptime', 'p95_latency_ms'])
        ->and($check['status'])->toBe('failed')
        ->and(json_encode($check))->not->toContain('10.0.3.4');
});

it('renders messages and tags when the details flag is on', function () {
    config()->set('alerts.route.details', true);
    failingTenantCheck();

    $this->getJson('health')
        ->assertStatus(503)
        ->assertJsonPath('checks.0.message', 'SQLSTATE[HY000] [2002] (Connection: tenant_a, Host: 10.0.3.4, Database: tenant_a_prod)')
        ->assertJsonPath('checks.0.tags', ['tenant-a'])
        ->assertJsonPath('checks.0.muted', false);
});

it('takes middleware on the route it registers', function () {
    $route = Health::routes('healthz')->middleware('auth.basic');

    expect($route->gatherMiddleware())->toBe(['auth.basic']);
});
