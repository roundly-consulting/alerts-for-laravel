<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Alerts\CheckResult;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Events\HealthCheckFailed;
use RoundlyConsulting\Alerts\Events\HealthCheckRecovered;
use RoundlyConsulting\Alerts\Exceptions\InvalidHealthCheck;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleNotification;
use RoundlyConsulting\Alerts\Tests\Models\Team;
use RoundlyConsulting\Alerts\Tests\Models\User;

it('runs a check synchronously and opens an alert', function () {
    ExampleHealthCheck::$ok = false;
    Notification::fake();
    Event::fake();

    $team = Team::create();
    User::create(['email' => 'john@doe.com']);

    $result = Health::for($team)->run(ExampleHealthCheck::class);

    expect($result)->toBeInstanceOf(CheckResult::class)
        ->and($result->status)->toBe(Status::Failed);

    $this->assertDatabaseHas('alerts', [
        'notifiable_type' => $team->getMorphClass(),
        'notifiable_id' => $team->getKey(),
    ]);

    Notification::assertSentTo(User::first(), ExampleNotification::class);
    Event::assertDispatched(HealthCheckFailed::class);
});

it('recovers an alert when a previously failing check passes', function () {
    Event::fake();
    $team = Team::create();

    ExampleHealthCheck::$ok = false;
    Health::for($team)->run(ExampleHealthCheck::class);

    ExampleHealthCheck::$ok = true;
    Health::for($team)->run(ExampleHealthCheck::class);

    Event::assertDispatched(HealthCheckRecovered::class);
    expect($team->alerts()->whereNotNull('recovered_at')->count())->toBe(1);
});

it('accepts a check instance', function () {
    $team = Team::create();

    $result = Health::for($team)->run(new ExampleHealthCheck);

    expect($result->isOk)->toBeTrue();
});

it('rejects a class that is not a check', function () {
    Health::for(Team::create())->run(stdClass::class);
})->throws(InvalidHealthCheck::class);

it('reads the overall status via the facade', function () {
    Health::check(ExampleHealthCheck::class);
    $team = Team::create();
    $team->createHealthCheck('example_health_check', '* * * * *');

    expect(Health::status())->toBe(Status::Ok);
});
