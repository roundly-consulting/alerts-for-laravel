<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Alerts\Actions\RunHealthCheckAction;
use RoundlyConsulting\Alerts\AlertSilence;
use RoundlyConsulting\Alerts\Checks\HttpPingCheck;
use RoundlyConsulting\Alerts\DataTransferObjects\ScheduleHealthCheckData;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Exceptions\InvalidHealthCheck;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\HealthCheckRun;
use RoundlyConsulting\Alerts\HealthManager;
use RoundlyConsulting\Alerts\Jobs\HealthCheckJob;
use RoundlyConsulting\Alerts\Support\NotifiableHealth;
use RoundlyConsulting\Alerts\Support\PendingScheduledCheck;
use RoundlyConsulting\Alerts\Support\Silences;
use RoundlyConsulting\Alerts\Tests\HealthChecks\AnotherHealthCheck;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\HealthChecks\RequiredArgumentCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;

beforeEach(function (): void {
    Health::checks([ExampleHealthCheck::class, AnotherHealthCheck::class]);
});

it('resolves the manager from the container and runs the same API', function (): void {
    $health = app(HealthManager::class);
    $team = Team::create();

    expect($health)->toBe(Health::getFacadeRoot())
        ->and($health->for($team))->toBeInstanceOf(NotifiableHealth::class)
        ->and($health->for($team)->run(ExampleHealthCheck::class)->isOk)->toBeTrue()
        ->and($health->status())->toBe(Status::Ok);
});

it('scopes a handle and a sub-accessor per call', function (): void {
    expect(Health::for(Team::create()))->toBeInstanceOf(NotifiableHealth::class)
        ->and(Health::silences())->toBeInstanceOf(Silences::class);
});

it('runs a check ad hoc for a notifiable', function (): void {
    ExampleHealthCheck::$ok = false;
    $team = Team::create();

    $result = Health::for($team)->run(ExampleHealthCheck::class);

    expect($result->status)->toBe(Status::Failed)
        ->and($team->alerts()->count())->toBe(1)
        ->and(Health::for($team)->status())->toBe(Status::Failed);
});

it('runs one of the notifiable\'s own scheduled rows', function (): void {
    $team = Team::create();
    $row = Health::for($team)->monitor(ExampleHealthCheck::class)->hourly()->save();

    Health::for($team)->run($row);

    expect($row->runs()->count())->toBe(1)
        ->and(HealthCheck::count())->toBe(1);
});

it('refuses to run a row scheduled for another notifiable', function (): void {
    $row = Health::for(Team::create())->monitor(ExampleHealthCheck::class)->save();

    Health::for(Team::create())->run($row);
})->throws(InvalidHealthCheck::class, 'is not scheduled for');

it('reports and reads status per notifiable and across all of them', function (): void {
    $healthy = Team::create();
    $failing = Team::create();

    Health::for($healthy)->run(ExampleHealthCheck::class);
    ExampleHealthCheck::$ok = false;
    Health::for($failing)->run(ExampleHealthCheck::class);

    expect(Health::for($healthy)->report()->checks())->toHaveCount(1)
        ->and(Health::for($healthy)->status())->toBe(Status::Ok)
        ->and(Health::for($failing)->status())->toBe(Status::Failed)
        ->and(Health::report()->checks())->toHaveCount(2)
        ->and(Health::status())->toBe(Status::Failed);
});

it('narrows the global report and status by tags', function (): void {
    $team = Team::create();
    Health::for($team)->monitor(ExampleHealthCheck::class)->tags(['db'])->save();
    Health::for($team)->monitor(AnotherHealthCheck::class)->tags(['cache'])->save();

    expect(Health::report(['db'])->checks())->toHaveCount(1)
        ->and(Health::report(['db'])->checks()[0]->key)->toBe('example_health_check')
        ->and(Health::status(['cache']))->toBe(Status::Ok)
        ->and(Health::for($team)->report(['nope'])->checks())->toBe([]);
});

it('schedules a check fluently through the handle', function (): void {
    $team = Team::create();

    $pending = Health::for($team)->monitor(ExampleHealthCheck::class);

    expect($pending)->toBeInstanceOf(PendingScheduledCheck::class);

    $row = $pending->everyFiveMinutes()->failAfter(3)->tags(['db'])->save();

    expect($row->exists)->toBeTrue()
        ->and($row->isScheduledFor($team))->toBeTrue()
        ->and($row->frequency)->toBe('*/5 * * * *')
        ->and($row->options()->failAfter())->toBe(3)
        ->and($row->tags)->toBe(['db']);
});

it('schedules a check from a DTO through the handle', function (): void {
    $team = Team::create();

    $row = Health::for($team)->schedule(new ScheduleHealthCheckData(
        check: ExampleHealthCheck::class,
        frequency: 'daily',
        timeout: 5,
    ));

    expect($row->health_check)->toBe('example_health_check')
        ->and($row->frequency)->toBe('@daily')
        ->and($row->options()->timeout())->toBe(5);
});

it('lists the notifiable\'s scheduled checks, oldest first', function (): void {
    $team = Team::create();
    Health::for(Team::create())->monitor(AnotherHealthCheck::class)->save();

    $first = Health::for($team)->monitor(ExampleHealthCheck::class)->save();
    $second = Health::for($team)->monitor(AnotherHealthCheck::class)->save();

    expect(Health::for($team)->monitors()->modelKeys())->toBe([$first->getKey(), $second->getKey()]);
});

it('unmonitors by class, key, check instance and own row', function (): void {
    $team = Team::create();
    $other = Team::create();

    Health::for($team)->monitor(ExampleHealthCheck::class)->save();
    Health::for($team)->monitor(ExampleHealthCheck::class)->hourly()->save();
    Health::for($other)->monitor(ExampleHealthCheck::class)->save();

    expect(Health::for($team)->unmonitor(ExampleHealthCheck::class))->toBe(2)
        ->and(Health::for($other)->monitors())->toHaveCount(1);

    Health::for($team)->monitor(AnotherHealthCheck::class)->save();
    expect(Health::for($team)->unmonitor('another_health_check'))->toBe(1);

    Health::for($team)->monitor(AnotherHealthCheck::class)->save();
    expect(Health::for($team)->unmonitor(new AnotherHealthCheck))->toBe(1);

    $row = Health::for($team)->monitor(AnotherHealthCheck::class)->save();
    expect(Health::for($team)->unmonitor($row))->toBe(1)
        ->and($row->fresh()?->trashed())->toBeTrue()
        ->and(Health::for($team)->monitors())->toHaveCount(0);
});

it('refuses to unmonitor a row scheduled for another notifiable', function (): void {
    $row = Health::for(Team::create())->monitor(ExampleHealthCheck::class)->save();

    try {
        Health::for(Team::create())->unmonitor($row);
    } finally {
        expect($row->fresh()?->trashed())->toBeFalse();
    }
})->throws(InvalidHealthCheck::class, 'is not scheduled for');

it('mutes, inspects and unmutes through silences()', function (): void {
    Carbon::setTestNow('2026-09-28 12:00:00');
    $team = Team::create();
    $other = Team::create();

    $silence = Health::silences()->mute('db', until: now()->addHour(), for: $team, reason: 'migration');

    expect($silence)->toBeInstanceOf(AlertSilence::class)
        ->and($silence->reason)->toBe('migration')
        ->and($silence->ends_at?->toDateTimeString())->toBe('2026-09-28 13:00:00')
        ->and(Health::silences()->isMuted('db', $team))->toBeTrue()
        ->and(Health::silences()->isMuted('db', $other))->toBeFalse();

    Health::silences()->mute('*');

    expect(Health::silences()->active())->toHaveCount(2)
        ->and(Health::silences()->active($team))->toHaveCount(2)
        ->and(Health::silences()->active($other)->pluck('key')->all())->toBe(['*'])
        ->and(Health::silences()->unmute('db'))->toBe(0)
        ->and(Health::silences()->unmute('db', $team))->toBe(1)
        ->and(Health::silences()->unmute('*'))->toBe(1)
        ->and(Health::silences()->active())->toHaveCount(0);

    Carbon::setTestNow();
});

it('leaves expired silences out of active()', function (): void {
    Carbon::setTestNow('2026-09-28 12:00:00');
    Health::silences()->mute('db', until: now()->addMinute());

    Carbon::setTestNow('2026-09-28 12:05:00');

    expect(Health::silences()->active())->toHaveCount(0);

    Carbon::setTestNow();
});

it('queues every due check with runDue()', function (): void {
    Queue::fake();
    Carbon::setTestNow('2026-09-28 14:00:00');
    $team = Team::create();

    Health::for($team)->monitor(ExampleHealthCheck::class)->hourly()->save();
    Health::for($team)->monitor(AnotherHealthCheck::class)->cron('30 * * * *')->save();

    expect(Health::runDue())->toBe(1);

    Queue::assertPushed(HealthCheckJob::class, 1);

    Carbon::setTestNow();
});

it('prunes run history with prune()', function (): void {
    config()->set('alerts.history.retention_days', 5);
    $row = Health::for(Team::create())->monitor(ExampleHealthCheck::class)->save();

    foreach ([10, 6, 2] as $daysAgo) {
        HealthCheckRun::factory()->create(['health_check_id' => $row->getKey(), 'ran_at' => now()->subDays($daysAgo)]);
    }

    expect(Health::prune(7))->toBe(1)
        ->and(Health::prune())->toBe(1)
        ->and(HealthCheckRun::count())->toBe(1);
});

it('schedules a check class-string under the key its registered instance carries', function (): void {
    Http::fake(['*' => Http::response('ok')]);
    Health::check(HttpPingCheck::make('https://status.example.test')->as('a_ping'));
    $team = Team::create();

    $row = Health::for($team)->monitor(HttpPingCheck::class)->save();

    expect($row->health_check)->toBe('a_ping')
        ->and(app(RunHealthCheckAction::class)->execute($row)->isOk)->toBeTrue();
});

it('unmonitors a check class-string by the key its registered instance carries', function (): void {
    Health::check(HttpPingCheck::make('https://status.example.test')->as('a_ping'));
    $team = Team::create();
    $row = Health::for($team)->monitor('a_ping')->save();

    expect(Health::for($team)->unmonitor(HttpPingCheck::class))->toBe(1)
        ->and($row->fresh()?->trashed())->toBeTrue();
});

it('schedules a registered check whose constructor needs arguments', function (): void {
    Health::check(new RequiredArgumentCheck('primary'));
    $team = Team::create();

    $row = Health::for($team)->monitor(RequiredArgumentCheck::class)->save();

    expect($row->health_check)->toBe('required_argument_check')
        ->and(Health::for($team)->unmonitor(RequiredArgumentCheck::class))->toBe(1);
});

it('resolves a check class-string through the registry under the fake too', function (): void {
    Health::check(HttpPingCheck::make('https://status.example.test')->as('a_ping'));
    Health::check(new RequiredArgumentCheck('primary'));
    $fake = Health::fake();
    $team = Team::create();

    $row = Health::for($team)->monitor(HttpPingCheck::class)->save();
    Health::for($team)->monitor(RequiredArgumentCheck::class)->save();
    Health::for($team)->unmonitor(HttpPingCheck::class);
    Health::for($team)->unmonitor(RequiredArgumentCheck::class);

    expect($row->health_check)->toBe('a_ping');

    $fake->assertMonitored('a_ping', $team);
    $fake->assertMonitored(HttpPingCheck::class, $team);
    $fake->assertMonitored(RequiredArgumentCheck::class, $team);
    $fake->assertUnmonitored('a_ping', $team);
    $fake->assertUnmonitored(RequiredArgumentCheck::class, $team);
});
