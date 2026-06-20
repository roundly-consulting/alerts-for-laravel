<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Alerts\Actions\RunHealthCheckAction;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Events\HealthCheckFailed;
use RoundlyConsulting\Alerts\Support\MonitorOptions;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;
use RoundlyConsulting\Alerts\Tests\Models\User;

it('opens no alert before the failure threshold is reached', function () {
    Notification::fake();
    Event::fake();

    ExampleHealthCheck::$status = Status::Failed;

    $team = Team::create();
    User::create(['email' => 'a@b.com']);
    $healthCheck = createHealthCheckWithNotifiable($team, meta: [MonitorOptions::FAIL_AFTER => 3]);

    app(RunHealthCheckAction::class)->execute($healthCheck);
    app(RunHealthCheckAction::class)->execute($healthCheck);

    $this->assertDatabaseEmpty('alerts');
    expect($healthCheck->refresh()->consecutive_failures)->toBe(2);
    expect($healthCheck->runs()->count())->toBe(2);
    Event::assertNotDispatched(HealthCheckFailed::class);
    Notification::assertNothingSent();
});

it('opens exactly one alert when the threshold is reached', function () {
    Notification::fake();
    Event::fake();

    ExampleHealthCheck::$status = Status::Failed;

    $team = Team::create();
    User::create(['email' => 'a@b.com']);
    $healthCheck = createHealthCheckWithNotifiable($team, meta: [MonitorOptions::FAIL_AFTER => 3]);

    app(RunHealthCheckAction::class)->execute($healthCheck);
    app(RunHealthCheckAction::class)->execute($healthCheck);
    app(RunHealthCheckAction::class)->execute($healthCheck);

    expect(RoundlyConsulting\Alerts\Alert::count())->toBe(1);
    Event::assertDispatchedTimes(HealthCheckFailed::class, 1);
});

it('resets the failure counter on a single ok result', function () {
    ExampleHealthCheck::$status = Status::Failed;

    $team = Team::create();
    User::create(['email' => 'a@b.com']);
    $healthCheck = createHealthCheckWithNotifiable($team, meta: [MonitorOptions::FAIL_AFTER => 3]);

    app(RunHealthCheckAction::class)->execute($healthCheck);
    app(RunHealthCheckAction::class)->execute($healthCheck);

    ExampleHealthCheck::$status = Status::Ok;
    app(RunHealthCheckAction::class)->execute($healthCheck);

    expect($healthCheck->refresh()->consecutive_failures)->toBe(0);

    ExampleHealthCheck::$status = Status::Failed;
    app(RunHealthCheckAction::class)->execute($healthCheck);

    $this->assertDatabaseEmpty('alerts');
});

it('opens immediately with the default fail-after of one', function () {
    Event::fake();

    ExampleHealthCheck::$status = Status::Failed;

    $team = Team::create();
    User::create(['email' => 'a@b.com']);
    $healthCheck = createHealthCheckWithNotifiable($team);

    app(RunHealthCheckAction::class)->execute($healthCheck);

    expect(RoundlyConsulting\Alerts\Alert::count())->toBe(1);
    Event::assertDispatched(HealthCheckFailed::class);
});
