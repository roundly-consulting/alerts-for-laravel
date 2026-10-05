<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Alerts\DataTransferObjects\ScheduleHealthCheckData;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Exceptions\InvalidHealthCheck;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\HealthManager;
use RoundlyConsulting\Alerts\Testing\HealthFake;
use RoundlyConsulting\Alerts\Tests\HealthChecks\AnotherHealthCheck;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;

it('swaps the facade and the container binding for a subtype of the manager', function (): void {
    $fake = Health::fake();

    expect($fake)->toBeInstanceOf(HealthFake::class)
        ->toBeInstanceOf(HealthManager::class)
        ->and(Health::getFacadeRoot())->toBe($fake)
        ->and(app(HealthManager::class))->toBe($fake);
});

it('keeps the checks registered before faking', function (): void {
    Health::check(ExampleHealthCheck::class);

    Health::fake();

    expect(Health::find('example_health_check'))->toBeInstanceOf(ExampleHealthCheck::class);
});

it('records runs without sending notifications', function (): void {
    Notification::fake();
    $fake = Health::fake();

    ExampleHealthCheck::$ok = false;

    Health::for(Team::create())->run(ExampleHealthCheck::class);

    $fake->assertChecked('example_health_check');
    $fake->assertAlerted('example_health_check');

    Notification::assertNothingSent();
    $this->assertDatabaseEmpty('alerts');
    $this->assertDatabaseEmpty('health_checks');
});

it('records a recovery when a failing check passes again', function (): void {
    $fake = Health::fake();
    $team = Team::create();

    ExampleHealthCheck::$ok = false;
    Health::for($team)->run(ExampleHealthCheck::class);
    ExampleHealthCheck::$ok = true;
    Health::for($team)->run(ExampleHealthCheck::class);

    $fake->assertAlerted('example_health_check');
    $fake->assertRecovered('example_health_check');
});

it('does not record an alert for a check muted in the fake', function (): void {
    $fake = Health::fake();
    ExampleHealthCheck::$ok = false;
    $team = Team::create();

    Health::silences()->mute('example_health_check', for: $team);
    Health::for($team)->run(ExampleHealthCheck::class);

    $fake->assertChecked('example_health_check');
    $fake->assertNothingAlerted();
});

it('runs a row of the notifiable and refuses another notifiable\'s row', function (): void {
    $team = Team::create();
    $row = createHealthCheckWithNotifiable($team);
    $fake = Health::fake();

    Health::for($team)->run($row);
    $fake->assertChecked('example_health_check');

    Health::for(Team::create())->run($row);
})->throws(InvalidHealthCheck::class);

it('answers report and status from the recorded runs, never the database', function (): void {
    Health::fake();
    $healthy = Team::create();
    $failing = Team::create();

    Health::for($healthy)->run(ExampleHealthCheck::class);
    ExampleHealthCheck::$ok = false;
    ExampleHealthCheck::$message = 'down';
    Health::for($failing)->run(ExampleHealthCheck::class);
    Health::for($failing)->run(AnotherHealthCheck::class);

    $failed = collect(Health::for($failing)->report()->checks())->firstWhere('key', 'example_health_check');

    expect(Health::report()->checks())->toHaveCount(3)
        ->and(Health::status())->toBe(Status::Failed)
        ->and(Health::for($healthy)->status())->toBe(Status::Ok)
        ->and($failed?->status)->toBe(Status::Failed)
        ->and($failed?->message)->toBe('down')
        ->and($failed?->uptime)->toBe(0.0)
        ->and($failed?->name)->toBe('Example Health Check')
        ->and(Health::report(['nope'])->checks())->toBe([]);

    $this->assertDatabaseEmpty('health_checks');
    $this->assertDatabaseEmpty('health_check_runs');
});

it('keeps silences in memory and flags muted checks in the report', function (): void {
    Carbon::setTestNow('2026-09-28 12:00:00');
    $fake = Health::fake();
    $team = Team::create();
    $other = Team::create();

    Health::silences()->mute('db', until: now()->addHour(), for: $team, reason: 'deploy');
    Health::silences()->mute('*');

    expect(Health::silences()->isMuted('db', $team))->toBeTrue()
        ->and(Health::silences()->isMuted('db', $other))->toBeFalse()
        ->and(Health::silences()->active())->toHaveCount(2)
        ->and(Health::silences()->active($other)->pluck('key')->all())->toBe(['*']);

    // A muted failure still opens the alert, flagged muted — as the pipeline does.
    ExampleHealthCheck::$ok = false;
    Health::for($team)->run(ExampleHealthCheck::class);
    expect(Health::report()->checks()[0]->muted)->toBeTrue();
    $fake->assertNothingAlerted();

    expect(Health::silences()->unmute('db', $team))->toBe(1)
        ->and(Health::silences()->unmute('*'))->toBe(1)
        ->and(Health::silences()->active())->toHaveCount(0);

    Health::silences()->mute('cache', until: now()->addMinute());
    Carbon::setTestNow('2026-09-28 12:05:00');
    expect(Health::silences()->isMuted('cache'))->toBeFalse();

    config()->set('alerts.silence', false);
    Health::silences()->mute('queue');
    expect(Health::silences()->isMuted('queue'))->toBeFalse();

    $fake->assertMuted('db', $team);
    $fake->assertUnmuted('*');
    $this->assertDatabaseEmpty('alert_silences');

    Carbon::setTestNow();
});

it('records schedules from the handle, the DTO and every trait entry point', function (): void {
    $fake = Health::fake();
    $team = Team::create();

    $pending = Health::for($team)->monitor(ExampleHealthCheck::class)->hourly()->save();
    Health::for($team)->schedule(new ScheduleHealthCheckData(check: 'dto_check'));
    $team->monitorCheck('trait_builder')->save();
    $team->monitor(new ScheduleHealthCheckData(check: 'trait_dto'));
    $team->createHealthCheck('trait_create', '* * * * *');

    // monitors() lists what was scheduled, as the real manager does (it used to be pinned
    // empty here, which was the fake diverging from the real API, not a contract).
    expect($pending->exists)->toBeFalse()
        ->and($pending->frequency)->toBe('@hourly')
        ->and(Health::for($team)->monitors())->toHaveCount(5)
        ->and(Health::for($team)->monitors()->first())->toBe($pending);

    $fake->assertMonitored(ExampleHealthCheck::class, $team);
    $fake->assertMonitored('dto_check');
    $fake->assertMonitored('trait_builder', $team);
    $fake->assertMonitored('trait_dto', $team);
    $fake->assertMonitored('trait_create', $team);
    $this->assertDatabaseEmpty('health_checks');
});

it('records unmonitoring and still refuses another notifiable\'s row', function (): void {
    $team = Team::create();
    $theirs = createHealthCheckWithNotifiable(Team::create());
    $mine = createHealthCheckWithNotifiable($team);
    $fake = Health::fake();

    expect(Health::for($team)->unmonitor(ExampleHealthCheck::class))->toBe(0);
    Health::for($team)->unmonitor('by_key');
    Health::for($team)->unmonitor(new AnotherHealthCheck);
    Health::for($team)->unmonitor($mine);

    $fake->assertUnmonitored(ExampleHealthCheck::class, $team);
    $fake->assertUnmonitored('by_key');
    $fake->assertUnmonitored('another_health_check', $team);
    expect($mine->fresh()?->trashed())->toBeFalse();

    Health::for($team)->unmonitor($theirs);
})->throws(InvalidHealthCheck::class);

it('records runDue and prune from the facade, the manager and the commands', function (): void {
    Queue::fake();
    createHealthCheckWithNotifiable(frequency: '* * * * *');
    $fake = Health::fake();

    expect(Health::runDue())->toBe(0)
        ->and(app(HealthManager::class)->prune(3))->toBe(0);

    $this->artisan('alerts:perform-health-checks')->assertSuccessful();
    $this->artisan('alerts:prune-runs')->assertSuccessful();

    $fake->assertRanDue(2);
    $fake->assertRanDue();
    $fake->assertPruned(3);
    $fake->assertPruned();
    Queue::assertNothingPushed();
});

it('passes every assertNothing* on a fresh fake', function (): void {
    $fake = Health::fake();

    $fake->assertNothingChecked();
    $fake->assertNothingAlerted();
    $fake->assertNothingRecovered();
    $fake->assertNothingMonitored();
    $fake->assertNothingUnmonitored();
    $fake->assertNothingMuted();
    $fake->assertNothingUnmuted();
    $fake->assertNothingRanDue();
    $fake->assertNothingPruned();

    expect(true)->toBeTrue();
});

dataset('failing assertions', [
    'assertChecked' => [fn (HealthFake $fake) => $fake->assertChecked('missing')],
    'assertAlerted' => [fn (HealthFake $fake) => $fake->assertAlerted('missing')],
    'assertRecovered' => [fn (HealthFake $fake) => $fake->assertRecovered('missing')],
    'assertMonitored' => [fn (HealthFake $fake) => $fake->assertMonitored('missing')],
    'assertUnmonitored' => [fn (HealthFake $fake) => $fake->assertUnmonitored('missing')],
    'assertMuted' => [fn (HealthFake $fake) => $fake->assertMuted('missing')],
    'assertUnmuted' => [fn (HealthFake $fake) => $fake->assertUnmuted('missing')],
    'assertRanDue' => [fn (HealthFake $fake) => $fake->assertRanDue()],
    'assertRanDue(times)' => [fn (HealthFake $fake) => $fake->assertRanDue(1)],
    'assertPruned' => [fn (HealthFake $fake) => $fake->assertPruned()],
]);

it('fails a positive assertion when nothing was recorded', function (Closure $assert): void {
    $assert(Health::fake());
})->with('failing assertions')->throws(ExpectationFailedException::class);

it('fails a scoped assertion recorded for another notifiable', function (Closure $act, Closure $assert): void {
    $fake = Health::fake();
    $team = Team::create();

    $act(Team::create());

    $assert($fake, $team);
})->with([
    'assertMonitored via the trait' => [
        fn (Team $other) => $other->monitorCheck(ExampleHealthCheck::class)->save(),
        fn (HealthFake $fake, Team $team) => $fake->assertMonitored(ExampleHealthCheck::class, $team),
    ],
    'assertUnmonitored' => [
        fn (Team $other) => Health::for($other)->unmonitor('db'),
        fn (HealthFake $fake, Team $team) => $fake->assertUnmonitored('db', $team),
    ],
    'assertMuted' => [
        fn (Team $other) => Health::silences()->mute('db', for: $other),
        fn (HealthFake $fake, Team $team) => $fake->assertMuted('db', $team),
    ],
    'assertUnmuted' => [
        fn (Team $other) => Health::silences()->unmute('db', $other),
        fn (HealthFake $fake, Team $team) => $fake->assertUnmuted('db', $team),
    ],
])->throws(ExpectationFailedException::class);

it('fails assertPruned for a different retention', function (): void {
    $fake = Health::fake();

    Health::prune(7);

    $fake->assertPruned(30);
})->throws(ExpectationFailedException::class);

it('fails an assertNothing* once the matching call was recorded', function (Closure $act, Closure $assert): void {
    $fake = Health::fake();

    $act(Team::create());

    $assert($fake);
})->with([
    'assertNothingChecked' => [
        fn (Team $team) => Health::for($team)->run(ExampleHealthCheck::class),
        fn (HealthFake $fake) => $fake->assertNothingChecked(),
    ],
    'assertNothingAlerted' => [
        function (Team $team): void {
            ExampleHealthCheck::$ok = false;
            Health::for($team)->run(ExampleHealthCheck::class);
        },
        fn (HealthFake $fake) => $fake->assertNothingAlerted(),
    ],
    'assertNothingRecovered' => [
        function (Team $team): void {
            ExampleHealthCheck::$ok = false;
            Health::for($team)->run(ExampleHealthCheck::class);
            ExampleHealthCheck::$ok = true;
            Health::for($team)->run(ExampleHealthCheck::class);
        },
        fn (HealthFake $fake) => $fake->assertNothingRecovered(),
    ],
    'assertNothingMonitored via the trait' => [
        fn (Team $team) => $team->createHealthCheck('db', '* * * * *'),
        fn (HealthFake $fake) => $fake->assertNothingMonitored(),
    ],
    'assertNothingUnmonitored' => [
        fn (Team $team) => Health::for($team)->unmonitor('db'),
        fn (HealthFake $fake) => $fake->assertNothingUnmonitored(),
    ],
    'assertNothingMuted' => [
        fn () => Health::silences()->mute('db'),
        fn (HealthFake $fake) => $fake->assertNothingMuted(),
    ],
    'assertNothingUnmuted' => [
        fn () => Health::silences()->unmute('db'),
        fn (HealthFake $fake) => $fake->assertNothingUnmuted(),
    ],
    'assertNothingRanDue' => [
        fn () => Health::runDue(),
        fn (HealthFake $fake) => $fake->assertNothingRanDue(),
    ],
    'assertNothingPruned' => [
        fn () => Health::prune(),
        fn (HealthFake $fake) => $fake->assertNothingPruned(),
    ],
])->throws(ExpectationFailedException::class);

it('records no recovery for a healthy run that had nothing to recover', function (): void {
    $fake = Health::fake();
    $team = Team::create();

    Health::for($team)->run(ExampleHealthCheck::class);
    $fake->assertNothingRecovered();

    ExampleHealthCheck::$ok = false;
    Health::for($team)->run(ExampleHealthCheck::class);
    ExampleHealthCheck::$ok = true;
    Health::for($team)->run(ExampleHealthCheck::class);

    $fake->assertRecovered('example_health_check');
});

it('turns a throwing check into a failed result like the real pipeline', function (): void {
    $fake = Health::fake();

    Health::define('flaky', function (): never {
        throw new RuntimeException('upstream exploded');
    });

    $result = Health::for(Team::create())->run('flaky');

    expect($result->status)->toBe(Status::Failed)
        ->and($result->message)->toBe('upstream exploded')
        ->and($result->meta['exception'])->toBe(RuntimeException::class);

    $fake->assertAlerted('flaky');
});

it('gates alerts and recoveries on failAfter and recoverAfter', function (): void {
    $fake = Health::fake();
    $team = Team::create();
    $healthy = false;

    Health::define('f3', function () use (&$healthy): bool {
        return $healthy;
    })->failAfter(3)->recoverAfter(2);

    Health::for($team)->run('f3');
    Health::for($team)->run('f3');
    $fake->assertNothingAlerted();
    expect(Health::for($team)->status())->toBe(Status::Ok);

    Health::for($team)->run('f3');
    $fake->assertAlerted('f3');

    $healthy = true;
    Health::for($team)->run('f3');
    $fake->assertNothingRecovered();
    // the alert is still open until the recovery is confirmed
    expect(Health::for($team)->status())->toBe(Status::Failed);

    Health::for($team)->run('f3');
    $fake->assertRecovered('f3');
    expect(Health::for($team)->status())->toBe(Status::Ok);
});

it('applies the options of a monitor recorded for the notifiable', function (): void {
    $fake = Health::fake();
    $team = Team::create();
    ExampleHealthCheck::$ok = false;

    Health::for($team)->monitor(ExampleHealthCheck::class)->failAfter(2)->save();

    Health::for($team)->run(ExampleHealthCheck::class);
    $fake->assertNothingAlerted();

    Health::for($team)->run(ExampleHealthCheck::class);
    $fake->assertAlerted('example_health_check');
});

it('refuses a schedule with an invalid cron before recording it', function (): void {
    $fake = Health::fake();

    try {
        Health::for(Team::create())->monitor(ExampleHealthCheck::class)->cron('0 9 * * FUNDAY')->save();
    } finally {
        $fake->assertNothingMonitored();
    }
})->throws(RoundlyConsulting\Alerts\Exceptions\InvalidCronExpression::class);

it('records a skipped run without touching the alert, like the pipeline', function (): void {
    $fake = Health::fake();
    $team = Team::create();
    ExampleHealthCheck::$status = Status::Skipped;

    Health::for($team)->run(ExampleHealthCheck::class);

    $fake->assertChecked('example_health_check');
    $fake->assertNothingAlerted();
    $fake->assertNothingRecovered();
    expect(Health::for($team)->status())->toBe(Status::Ok);
});

it('keeps an open alert in step with a failure below the gate', function (): void {
    $fake = Health::fake();
    $team = Team::create();
    $status = Status::Failed;

    Health::define('flip', function () use (&$status): RoundlyConsulting\Alerts\CheckResult {
        return new RoundlyConsulting\Alerts\CheckResult($status, $status->value);
    })->failAfter(2)->recoverAfter(2);

    Health::for($team)->run('flip');
    Health::for($team)->run('flip');
    $fake->assertAlerted('flip');

    // One success resets the failure count without closing the alert; the next
    // warning is below failAfter again, yet the open alert must report it.
    $status = Status::Ok;
    Health::for($team)->run('flip');
    $status = Status::Warning;
    Health::for($team)->run('flip');

    expect(Health::for($team)->report()->checks()[0]->status)->toBe(Status::Warning)
        ->and(Health::for($team)->report()->checks()[0]->message)->toBe('warning');
});

it('refuses a prune below one day under the fake too, recording nothing', function (): void {
    $fake = Health::fake();

    expect(fn () => Health::prune(0))->toThrow(RoundlyConsulting\Alerts\Exceptions\InvalidRetention::class);

    $fake->assertNothingPruned();
});

/*
 * Parity with the real manager: each scenario runs once against the database and once
 * under Health::fake(), and must come out the same both times.
 */
it('gives the real manager\'s outcome under the fake', function (Closure $scenario, array $expected): void {
    Health::checks([ExampleHealthCheck::class, AnotherHealthCheck::class]);

    $real = $scenario(Team::create());

    Health::fake();
    $faked = $scenario(Team::create());

    expect($real)->toBe($expected)
        ->and($faked)->toBe($expected);
})->with([
    'the oldest of two monitors decides the run' => [
        function (Team $team): array {
            ExampleHealthCheck::$ok = false;
            Health::for($team)->monitor(ExampleHealthCheck::class)->failAfter(1)->save();
            Health::for($team)->monitor(ExampleHealthCheck::class)->failAfter(3)->save();

            Health::for($team)->run(ExampleHealthCheck::class);

            return [Health::for($team)->status()->value, count(Health::for($team)->report()->checks())];
        },
        ['failed', 2],
    ],
    'monitors() lists what was scheduled' => [
        function (Team $team): array {
            Health::for($team)->monitor(ExampleHealthCheck::class)->hourly()->save();
            Health::for($team)->monitor(AnotherHealthCheck::class)->daily()->save();
            Health::for(Team::create())->monitor(ExampleHealthCheck::class)->save();

            return Health::for($team)->monitors()
                ->map(fn (RoundlyConsulting\Alerts\HealthCheck $row): array => [$row->health_check, $row->frequency])
                ->all();
        },
        [['example_health_check', '@hourly'], ['another_health_check', '@daily']],
    ],
    'a monitor that never ran is in the report' => [
        function (Team $team): array {
            Health::for($team)->monitor(ExampleHealthCheck::class)->tags(['db'])->save();

            return array_map(
                fn (RoundlyConsulting\Alerts\Status\CheckStatus $check): array => [$check->key, $check->status->value, $check->tags, $check->uptime],
                Health::for($team)->report()->checks(),
            );
        },
        [['example_health_check', 'ok', ['db'], 100.0]],
    ],
    'unmonitor drops the monitor\'s options' => [
        function (Team $team): array {
            ExampleHealthCheck::$ok = false;
            Health::for($team)->monitor(ExampleHealthCheck::class)->failAfter(3)->save();
            $removed = Health::for($team)->unmonitor(ExampleHealthCheck::class);

            Health::for($team)->run(ExampleHealthCheck::class);

            return [$removed, Health::for($team)->status()->value, count(Health::for($team)->report()->checks())];
        },
        [1, 'failed', 1],
    ],
    'unmonitor drops the monitor from the report' => [
        function (Team $team): array {
            ExampleHealthCheck::$ok = false;
            Health::for($team)->monitor(ExampleHealthCheck::class)->save();
            Health::for($team)->run(ExampleHealthCheck::class);
            $removed = Health::for($team)->unmonitor(ExampleHealthCheck::class);

            return [$removed, Health::for($team)->status()->value, count(Health::for($team)->report()->checks()), Health::for($team)->monitors()->count()];
        },
        [1, 'ok', 0, 0],
    ],
    'unmonitor of one row keeps the others' => [
        function (Team $team): array {
            $first = Health::for($team)->monitor(ExampleHealthCheck::class)->hourly()->save();
            Health::for($team)->monitor(ExampleHealthCheck::class)->daily()->save();

            return [Health::for($team)->unmonitor($first), Health::for($team)->monitors()->pluck('frequency')->all()];
        },
        [1, ['@daily']],
    ],
    'running a scheduled row uses that row' => [
        function (Team $team): array {
            ExampleHealthCheck::$ok = false;
            Health::for($team)->monitor(ExampleHealthCheck::class)->failAfter(3)->save();
            $second = Health::for($team)->monitor(ExampleHealthCheck::class)->failAfter(1)->save();

            Health::for($team)->run($second);

            return array_map(
                fn (RoundlyConsulting\Alerts\Status\CheckStatus $check): string => $check->status->value,
                Health::for($team)->report()->checks(),
            );
        },
        ['ok', 'failed'],
    ],
]);
