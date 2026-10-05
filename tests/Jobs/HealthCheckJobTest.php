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
    $this->app->call([$job, 'handle']);

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
    $this->app->call([$job, 'handle']);

    // `meta` stays out of the database predicate even though `jsonb` now has an `=`
    // operator. Postgres would compare it semantically, but SQLite compares the column as
    // text, so a multi-key `castAsJson` would pin insertion order there — trading the old
    // driver-dependence for a new one. The merged payload is asserted through the cast
    // below, which is what the job actually contracts.
    $this->assertDatabaseHas('alerts', [
        'notifiable_type' => Team::class,
        'notifiable_id' => 1,
        'health_check_id' => 1,
        'triggered_at' => '2023-03-22 12:50:00',
        'message' => 'Something terrible happened',
    ]);

    // `toEqual`, not `toBe`: the contract is which keys hold which values, not the order
    // they come back in. `jsonb` normalises object keys (shortest first, then bytewise), so
    // `meta` sorts ahead of `definition-meta` on Postgres — a `toBe` here would pin storage
    // order rather than the merge, and would pass only on SQLite.
    expect(Alert::query()->sole()->meta)->toEqual([
        'definition-meta' => ['specific-thing' => 'yes'],
        'meta' => ['ufo' => 'exists'],
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
    $this->app->call([$job, 'handle']);

    $this->assertDatabaseCount('alerts', 1);

    Event::assertDispatched(HealthCheckFailed::class);
});

it('captures recovery timestamp of failed health check', function () {
    ExampleHealthCheck::$ok = false;
    Carbon::setTestNow('2023-03-22 12:50:00');
    Event::fake();

    $healthCheck = createHealthCheckWithNotifiable();
    $job = new HealthCheckJob($healthCheck);
    $this->app->call([$job, 'handle']);

    ExampleHealthCheck::$ok = true;
    Carbon::setTestNow('2023-03-22 12:55:15');
    $this->app->call([$job, 'handle']);

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

it('skips a row that was unmonitored after the job was queued', function () {
    ExampleHealthCheck::$ok = false;
    Notification::fake();

    $team = Team::create();
    $user = User::create(['email' => 'john@doe.com']);
    $healthCheck = createHealthCheckWithNotifiable($team);

    // Queued while monitored, restored by SerializesModels after unmonitor() — which
    // restores without the soft-delete scope, so the row comes back trashed.
    $payload = serialize(new HealthCheckJob($healthCheck));
    RoundlyConsulting\Alerts\Facades\Health::for($team)->unmonitor('example_health_check');

    $job = unserialize($payload);
    $this->app->call([$job, 'handle']);

    expect($job->healthCheck->trashed())->toBeTrue()
        ->and($healthCheck->runs()->count())->toBe(0);
    $this->assertDatabaseEmpty('alerts');
    Notification::assertNothingSent();
});
