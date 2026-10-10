<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;

/**
 * `CheckStatus::$meta` is the open alert's raw meta, which can hold exception text. The
 * unauthenticated endpoint never renders it, details or not.
 */
it('never renders the meta on the health endpoint', function (bool $details): void {
    config()->set('alerts.route.details', $details);
    Health::routes('health');
    Health::check(ExampleHealthCheck::class);

    $team = Team::create();
    $row = $team->createHealthCheck('example_health_check', '* * * * *');

    Alert::create([
        'notifiable_type' => $team->getMorphClass(),
        'notifiable_id' => $team->getKey(),
        'health_check_id' => $row->getKey(),
        'status' => Status::Failed,
        'meta' => ['exception_message' => 'SQLSTATE password=hunter2'],
        'triggered_at' => now(),
    ]);

    expect(Health::report()->checks()[0]->meta)->toHaveKey('exception_message');

    $check = $this->getJson('health')->assertStatus(503)->json('checks.0');

    expect($check)->not->toHaveKey('meta')
        ->and(json_encode($check))->not->toContain('hunter2')
        ->and(array_keys($check))->toBe($details
            ? ['key', 'name', 'status', 'last_alert_at', 'message', 'tags', 'uptime', 'p95_latency_ms', 'muted']
            : ['key', 'name', 'status', 'uptime', 'p95_latency_ms']);
})->with(['default' => false, 'details' => true]);
