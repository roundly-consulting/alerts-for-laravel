<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Alerts\Actions\RunHealthCheckAction;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Events\HealthCheckFailed;
use RoundlyConsulting\Alerts\Exceptions\CheckTimedOut;
use RoundlyConsulting\Alerts\Support\MonitorOptions;
use RoundlyConsulting\Alerts\Tests\HealthChecks\SlowHealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;
use RoundlyConsulting\Alerts\Tests\Models\User;

it('fails a timed-out check through the normal failed path without rethrowing', function () {
    Notification::fake();
    Event::fake();

    SlowHealthCheck::$sleepMicroseconds = 1_200_000; // 1.2s, beyond a 1s budget

    RoundlyConsulting\Alerts\Facades\Health::check(SlowHealthCheck::class);

    $team = Team::create();
    User::create(['email' => 'a@b.com']);

    $healthCheck = createHealthCheckWithNotifiable(
        $team,
        'slow_health_check',
        meta: [MonitorOptions::TIMEOUT => 1],
    );

    $result = app(RunHealthCheckAction::class)->execute($healthCheck);

    expect($result->status)->toBe(Status::Failed)
        ->and($result->meta['exception'])->toBe(CheckTimedOut::class)
        ->and($result->meta['timed_out_after'])->toBe(1);

    $this->assertDatabaseHas('alerts', [
        'health_check_id' => $healthCheck->getKey(),
        'status' => 'failed',
    ]);
    $this->assertDatabaseHas('health_check_runs', [
        'health_check_id' => $healthCheck->getKey(),
        'status' => 'failed',
    ]);

    Event::assertDispatched(HealthCheckFailed::class);
})->group('slow');

it('runs a fast check within budget without failing', function () {
    Notification::fake();

    SlowHealthCheck::$sleepMicroseconds = 1000;

    RoundlyConsulting\Alerts\Facades\Health::check(SlowHealthCheck::class);

    $team = Team::create();
    User::create(['email' => 'a@b.com']);

    $healthCheck = createHealthCheckWithNotifiable(
        $team,
        'slow_health_check',
        meta: [MonitorOptions::TIMEOUT => 5],
    );

    $result = app(RunHealthCheckAction::class)->execute($healthCheck);

    expect($result->status)->toBe(Status::Ok);
    $this->assertDatabaseEmpty('alerts');
});
