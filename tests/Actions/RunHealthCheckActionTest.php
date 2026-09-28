<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Alerts\Actions\RunHealthCheckAction;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Events\HealthCheckFailed;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleNotification;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ThrowingHealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;
use RoundlyConsulting\Alerts\Tests\Models\User;

beforeEach(fn () => Carbon::setTestNow('2023-03-22 12:50:00'));
afterEach(fn () => Carbon::setTestNow());

it('opens a warning alert and notifies for a warning result', function () {
    ExampleHealthCheck::$status = Status::Warning;
    ExampleHealthCheck::$message = 'Degraded';

    Notification::fake();
    Event::fake();

    $healthCheck = createHealthCheckWithNotifiable();
    $user = User::create(['email' => 'john@doe.com']);

    app(RunHealthCheckAction::class)->execute($healthCheck);

    $this->assertDatabaseHas('alerts', [
        'health_check_id' => $healthCheck->getKey(),
        'status' => 'warning',
        'message' => 'Degraded',
    ]);

    Notification::assertSentTo($user, ExampleNotification::class);
    Event::assertDispatched(HealthCheckFailed::class);
});

it('records nothing for a skipped result', function () {
    ExampleHealthCheck::$status = Status::Skipped;

    Event::fake();

    $healthCheck = createHealthCheckWithNotifiable();

    $result = app(RunHealthCheckAction::class)->execute($healthCheck);

    expect($result->status)->toBe(Status::Skipped);
    $this->assertDatabaseEmpty('alerts');
    Event::assertNotDispatched(HealthCheckFailed::class);
});

it('persists the failed status on the alert', function () {
    ExampleHealthCheck::$status = Status::Failed;
    Event::fake();

    $healthCheck = createHealthCheckWithNotifiable();

    app(RunHealthCheckAction::class)->execute($healthCheck);

    $this->assertDatabaseHas('alerts', [
        'health_check_id' => $healthCheck->getKey(),
        'status' => 'failed',
    ]);
});

it('returns the result for a healthy check', function () {
    $healthCheck = createHealthCheckWithNotifiable();

    $result = app(RunHealthCheckAction::class)->execute($healthCheck);

    expect($result->isOk)->toBeTrue();
    $this->assertDatabaseEmpty('alerts');
});

it('converts a thrown check into a failed alert and recorded run without rethrowing', function () {
    Notification::fake();
    Event::fake();

    Health::check(ThrowingHealthCheck::class);
    $team = Team::create();
    User::create(['email' => 'john@doe.com']);

    $healthCheck = createHealthCheckWithNotifiable($team, 'throwing_health_check');

    $result = app(RunHealthCheckAction::class)->execute($healthCheck);

    expect($result->status)->toBe(Status::Failed)
        ->and($result->meta['exception'])->toBe(RuntimeException::class);

    $this->assertDatabaseHas('alerts', [
        'health_check_id' => $healthCheck->getKey(),
        'status' => 'failed',
    ]);

    $this->assertDatabaseHas('health_check_runs', [
        'health_check_id' => $healthCheck->getKey(),
        'status' => 'failed',
    ]);

    Event::assertDispatched(HealthCheckFailed::class);
});

it('throws when notifiable is missing', function () {
    ExampleHealthCheck::$status = Status::Failed;

    $healthCheck = createHealthCheckWithNotifiable();
    $healthCheck->notifiable_id = 999999;
    $healthCheck->save();
    $healthCheck->setRelation('notifiable', null);

    app(RunHealthCheckAction::class)->execute($healthCheck);
})->throws(RoundlyConsulting\Alerts\Exceptions\InvalidNotifiableForHealthCheck::class);

it('keeps an open alert in step with the latest failing result', function () {
    Notification::fake();

    $healthCheck = createHealthCheckWithNotifiable();

    ExampleHealthCheck::$status = Status::Warning;
    ExampleHealthCheck::$message = 'Disk 85%';
    app(RunHealthCheckAction::class)->execute($healthCheck->fresh());

    ExampleHealthCheck::$status = Status::Failed;
    ExampleHealthCheck::$message = 'Disk 100% FULL';
    app(RunHealthCheckAction::class)->execute($healthCheck->fresh());

    $check = Health::report()->checks()[0];

    expect(RoundlyConsulting\Alerts\Alert::query()->count())->toBe(1)
        ->and($check->status)->toBe(Status::Failed)
        ->and($check->message)->toBe('Disk 100% FULL')
        ->and(Health::status())->toBe(Status::Failed);

    ExampleHealthCheck::$status = Status::Warning;
    ExampleHealthCheck::$message = 'Disk 90%';
    app(RunHealthCheckAction::class)->execute($healthCheck->fresh());

    expect(Health::report()->checks()[0]->status)->toBe(Status::Warning)
        ->and(Health::report()->checks()[0]->message)->toBe('Disk 90%');
});
