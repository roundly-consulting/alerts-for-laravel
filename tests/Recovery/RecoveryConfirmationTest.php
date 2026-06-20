<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Alerts\Actions\RunHealthCheckAction;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Events\HealthCheckRecovered;
use RoundlyConsulting\Alerts\Support\MonitorOptions;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;
use RoundlyConsulting\Alerts\Tests\Models\User;

beforeEach(function () {
    Notification::fake();
});

it('does not recover before the success threshold', function () {
    Event::fake();

    $team = Team::create();
    User::create(['email' => 'a@b.com']);
    $healthCheck = createHealthCheckWithNotifiable($team, meta: [MonitorOptions::RECOVER_AFTER => 2]);

    ExampleHealthCheck::$status = Status::Failed;
    app(RunHealthCheckAction::class)->execute($healthCheck);

    ExampleHealthCheck::$status = Status::Ok;
    app(RunHealthCheckAction::class)->execute($healthCheck);

    $alert = RoundlyConsulting\Alerts\Alert::first();
    expect($alert->recovered_at)->toBeNull();
    Event::assertNotDispatched(HealthCheckRecovered::class);
});

it('recovers once the success threshold is reached and resets escalation', function () {
    Event::fake();

    $team = Team::create();
    User::create(['email' => 'a@b.com']);
    $healthCheck = createHealthCheckWithNotifiable($team, meta: [MonitorOptions::RECOVER_AFTER => 2]);

    ExampleHealthCheck::$status = Status::Failed;
    app(RunHealthCheckAction::class)->execute($healthCheck);

    ExampleHealthCheck::$status = Status::Ok;
    app(RunHealthCheckAction::class)->execute($healthCheck);
    app(RunHealthCheckAction::class)->execute($healthCheck);

    $alert = RoundlyConsulting\Alerts\Alert::first();
    expect($alert->recovered_at)->not->toBeNull()
        ->and($alert->escalation_level)->toBe(0)
        ->and($healthCheck->refresh()->consecutive_successes)->toBe(0);

    Event::assertDispatchedTimes(HealthCheckRecovered::class, 1);
});

it('resets the success counter when a failure occurs between successes', function () {
    $team = Team::create();
    User::create(['email' => 'a@b.com']);
    $healthCheck = createHealthCheckWithNotifiable($team, meta: [MonitorOptions::RECOVER_AFTER => 2]);

    ExampleHealthCheck::$status = Status::Failed;
    app(RunHealthCheckAction::class)->execute($healthCheck);

    ExampleHealthCheck::$status = Status::Ok;
    app(RunHealthCheckAction::class)->execute($healthCheck);

    ExampleHealthCheck::$status = Status::Failed;
    app(RunHealthCheckAction::class)->execute($healthCheck);

    expect($healthCheck->refresh()->consecutive_successes)->toBe(0);
});

it('recovers immediately with the default recover-after of one', function () {
    Event::fake();

    $team = Team::create();
    User::create(['email' => 'a@b.com']);
    $healthCheck = createHealthCheckWithNotifiable($team);

    ExampleHealthCheck::$status = Status::Failed;
    app(RunHealthCheckAction::class)->execute($healthCheck);

    ExampleHealthCheck::$status = Status::Ok;
    app(RunHealthCheckAction::class)->execute($healthCheck);

    expect(RoundlyConsulting\Alerts\Alert::first()->recovered_at)->not->toBeNull();
    Event::assertDispatched(HealthCheckRecovered::class);
});
