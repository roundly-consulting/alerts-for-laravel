<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\Alerts\Events\HealthCheckFailed;
use RoundlyConsulting\Alerts\Events\HealthCheckRecovered;
use RoundlyConsulting\Alerts\Jobs\HealthCheckJob;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleNotification;
use RoundlyConsulting\Alerts\Tests\Models\Team;
use RoundlyConsulting\Alerts\Tests\Models\User;

it('does not create alert when health check is ok', function () {
    ExampleHealthCheck::$ok = true;

    $healthCheck = createHealthCheckWithNotifiable();

    $job = new HealthCheckJob($healthCheck);
    $job->handle();

    $this->assertDatabaseEmpty('alerts');
});

it('creates alert and sends alert notification when health check failed', function () {
    ExampleHealthCheck::$ok = false;
    ExampleHealthCheck::$message = 'Something terrible happened';
    ExampleHealthCheck::$meta = ['ufo' => 'exists'];

    Carbon::setTestNow('2023-03-22 12:50:00');
    Notification::fake();
    Event::fake();

    $healthCheck = createHealthCheckWithNotifiable(meta: [
        'specific-thing' => 'yes',
    ]);

    $user = User::create(['email' => 'john@doe.com']);

    $job = new HealthCheckJob($healthCheck);
    $job->handle();

    $this->assertDatabaseHas('alerts', [
        'notifiable_type' => Team::class,
        'notifiable_id' => 1,
        'health_check_id' => 1,
        'triggered_at' => '2023-03-22 12:50:00',
        'message' => 'Something terrible happened',
        'meta' => json_encode(['definition-meta' => ['specific-thing' => 'yes'], 'meta' => ['ufo' => 'exists']]),
    ]);

    Notification::assertSentTo($user, ExampleNotification::class);
    Event::assertDispatched(HealthCheckFailed::class);
});

it('does not create alert when not recovered alert already exists', function () {
    ExampleHealthCheck::$ok = false;

    Carbon::setTestNow('2023-03-22 12:50:00');
    Event::fake();

    $healthCheck = createHealthCheckWithNotifiable();

    Alert::create([
        'notifiable_type' => Team::class,
        'notifiable_id' => 1,
        'health_check_id' => 1,
        'triggered_at' => '2023-03-22 12:50:00',
    ]);

    $this->assertDatabaseHas('alerts', [
        'notifiable_type' => Team::class,
        'notifiable_id' => 1,
        'health_check_id' => 1,
        'triggered_at' => '2023-03-22 12:50:00',
    ]);

    $job = new HealthCheckJob($healthCheck);
    $job->handle();

    $this->assertDatabaseCount('alerts', 1);

    Event::assertDispatched(HealthCheckFailed::class);
});

it('captures recovery timestamp of failed health check', function () {
    ExampleHealthCheck::$ok = false;
    Carbon::setTestNow('2023-03-22 12:50:00');
    Event::fake();

    $healthCheck = createHealthCheckWithNotifiable();
    $job = new HealthCheckJob($healthCheck);
    $job->handle();

    ExampleHealthCheck::$ok = true;
    Carbon::setTestNow('2023-03-22 12:55:15');
    $job->handle();

    $this->assertDatabaseHas('alerts', [
        'notifiable_type' => Team::class,
        'notifiable_id' => 1,
        'health_check_id' => 1,
        'triggered_at' => '2023-03-22 12:50:00',
        'recovered_at' => '2023-03-22 12:55:15',
    ]);

    Event::assertDispatched(HealthCheckFailed::class);
    Event::assertDispatched(HealthCheckRecovered::class);
});
