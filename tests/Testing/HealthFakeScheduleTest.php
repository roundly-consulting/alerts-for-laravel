<?php

declare(strict_types=1);

use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Alerts\DataTransferObjects\ReconfigureHealthCheckData;
use RoundlyConsulting\Alerts\DataTransferObjects\ScheduleHealthCheckData;
use RoundlyConsulting\Alerts\Exceptions\InvalidHealthCheck;
use RoundlyConsulting\Alerts\Exceptions\ScheduleConflict;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\HealthManager;
use RoundlyConsulting\Alerts\Testing\HealthFake;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ServiceCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;

/**
 * Under the fake, a `uniqueBy()` schedule is upserted in memory with the real diff and
 * counter-reset rule, and `reconfigure()` merges in memory — recorded for the assertions.
 */
beforeEach(function (): void {
    Health::checks([ExampleHealthCheck::class, ServiceCheck::class]);
});

it('keeps one in-memory schedule per identity, through the trait too', function (): void {
    $fake = Health::fake();
    $team = Team::create();

    $first = Health::for($team)->monitor(ServiceCheck::class)->meta(['service' => 'api'])->save();
    $second = $team->monitorCheck(ServiceCheck::class)->meta(['service' => 'api'])->save();
    Health::for($team)->monitor(ServiceCheck::class)->meta(['service' => 'web'])->save();
    Health::for($team)->monitor(ExampleHealthCheck::class)->save();
    Health::for($team)->monitor(ExampleHealthCheck::class)->save();

    // A fresh copy per call, as the real manager re-reads the row.
    expect($second)->not->toBe($first)
        ->and($second->meta)->toBe($first->meta)
        ->and($second->wasRecentlyCreated)->toBeFalse()
        ->and($first->wasRecentlyCreated)->toBeTrue()
        ->and($second->wasChanged())->toBeFalse()
        ->and(Health::for($team)->monitors())->toHaveCount(4);

    $fake->assertMonitored(ServiceCheck::class, $team);
    $this->assertDatabaseEmpty('health_checks');
});

it('resets the in-memory counters only when a schedule changes', function (): void {
    $fake = Health::fake();
    $team = Team::create();
    ServiceCheck::$ok = false;

    $schedule = fn (int $failAfter): HealthCheck => Health::for($team)->schedule(new ScheduleHealthCheckData(
        check: ServiceCheck::class,
        failAfter: $failAfter,
        meta: ['service' => 'api'],
    ));

    $schedule(2);
    Health::for($team)->run(ServiceCheck::class);

    // Identical: the one failure is kept, so the next one reaches failAfter.
    expect($schedule(2)->wasChanged())->toBeFalse();
    Health::for($team)->run(ServiceCheck::class);
    $fake->assertAlerted('service_check');

    // Changed: the counters start over, so one failure is below failAfter again.
    $changed = $schedule(3);

    expect($changed->wasChanged())->toBeTrue()
        ->and($changed->getChanges())->toHaveKey('meta');

    ServiceCheck::$ok = true;
})->after(fn () => ServiceCheck::$ok = true);

it('reconfigures in memory and records it', function (): void {
    $fake = Health::fake();
    $team = Team::create();
    $row = Health::for($team)->monitor(ServiceCheck::class)->meta(['service' => 'api', 'region' => 'eu'])->tags(['db'])->save();

    $same = Health::for($team)->reconfigure($row, new ReconfigureHealthCheckData(tags: ['db']));

    expect($same->wasChanged())->toBeFalse();

    Health::for($team)->reconfigure($row, new ReconfigureHealthCheckData(frequency: 'hourly', tags: [], meta: ['region' => null]));

    expect($row->frequency)->toBe('@hourly')
        ->and($row->tags)->toBeNull()
        ->and($row->meta)->toBe(['service' => 'api'])
        ->and($row->wasChanged())->toBeTrue()
        ->and(Health::for($team)->monitors()->first())->toBe($row);

    $fake->assertReconfigured(ServiceCheck::class, $team);
    $fake->assertReconfigured('service_check');
    $this->assertDatabaseEmpty('health_checks');
});

it('refuses another notifiable\'s row and a conflict under the fake, recording neither', function (): void {
    $fake = Health::fake();
    $team = Team::create();
    $api = Health::for($team)->monitor(ServiceCheck::class)->meta(['service' => 'api'])->save();
    Health::for($team)->monitor(ServiceCheck::class)->meta(['service' => 'web'])->save();

    expect(fn () => Health::for(Team::create())->reconfigure($api, new ReconfigureHealthCheckData(maxAttempts: 2)))
        ->toThrow(InvalidHealthCheck::class)
        ->and(fn () => Health::for($team)->reconfigure($api, new ReconfigureHealthCheckData(meta: ['service' => 'web'])))
        ->toThrow(ScheduleConflict::class)
        ->and($api->meta)->toBe(['service' => 'api']);

    $fake->assertNothingReconfigured();
});

it('keeps the fake\'s schedules of other checks free of an identity on reconfigure', function (): void {
    Health::fake();
    $team = Team::create();

    $plain = Health::for($team)->monitor(ExampleHealthCheck::class)->save();
    $unregistered = Health::for($team)->schedule(new ScheduleHealthCheckData(check: 'dto_check'));

    Health::for($team)->reconfigure($plain, new ReconfigureHealthCheckData(meta: ['service' => 'api']));
    Health::for($team)->reconfigure($unregistered, new ReconfigureHealthCheckData(meta: ['service' => 'api']));

    expect($plain->identity_hash)->toBeNull()
        ->and($plain->meta)->toBe(['service' => 'api'])
        ->and($unregistered->identity_hash)->toBeNull()
        ->and($unregistered->meta)->toBe(['service' => 'api']);
});

it('reconfigures a database row in memory without writing it', function (): void {
    $team = Team::create();
    $row = createHealthCheckWithNotifiable($team);
    $fake = Health::fake();

    Health::for($team)->reconfigure($row, new ReconfigureHealthCheckData(maxAttempts: 7));

    expect($row->max_attempts)->toBe(7)
        ->and($row->fresh()?->max_attempts)->toBe(1);

    $fake->assertReconfigured('example_health_check', $team);
});

it('fails assertReconfigured when nothing or another notifiable was reconfigured', function (Closure $act, Closure $assert): void {
    $fake = Health::fake();
    $team = Team::create();

    $act(Team::create());

    $assert($fake, $team);
})->with([
    'nothing' => [fn () => null, fn (HealthFake $fake) => $fake->assertReconfigured('service_check')],
    'another notifiable' => [
        function (Team $other): void {
            $row = Health::for($other)->monitor(ServiceCheck::class)->save();
            Health::for($other)->reconfigure($row, new ReconfigureHealthCheckData(maxAttempts: 2));
        },
        fn (HealthFake $fake, Team $team) => $fake->assertReconfigured(ServiceCheck::class, $team),
    ],
    'assertNothingReconfigured' => [
        function (Team $other): void {
            $row = Health::for($other)->monitor(ServiceCheck::class)->save();
            Health::for($other)->reconfigure($row, new ReconfigureHealthCheckData(maxAttempts: 2));
        },
        fn (HealthFake $fake) => $fake->assertNothingReconfigured(),
    ],
])->throws(ExpectationFailedException::class);

/*
 * Parity: the same scenario against the database and under the fake.
 */
it('gives the real manager\'s schedule outcome under the fake', function (Closure $scenario, array $expected): void {
    $real = $scenario(Team::create());

    Health::fake();
    $faked = $scenario(Team::create());

    expect($real)->toBe($expected)
        ->and($faked)->toBe($expected);
})->with([
    'an identical re-schedule changes nothing' => [
        function (Team $team): array {
            $first = Health::for($team)->monitor(ServiceCheck::class)->meta(['service' => 'api'])->tags(['a', 'b'])->save();
            $again = Health::for($team)->monitor(ServiceCheck::class)->meta(['service' => 'api'])->tags(['b', 'a'])->save();

            return [$first->wasRecentlyCreated, $again->wasChanged(), Health::for($team)->monitors()->count()];
        },
        [true, false, 1],
    ],
    'a changed re-schedule replaces the columns' => [
        function (Team $team): array {
            Health::for($team)->monitor(ServiceCheck::class)->meta(['service' => 'api'])->tags(['a'])->save();
            $again = Health::for($team)->monitor(ServiceCheck::class)->meta(['service' => 'api'])->hourly()->save();

            return [$again->wasChanged(), $again->frequency, $again->tags, Health::for($team)->monitors()->count()];
        },
        [true, '@hourly', null, 1],
    ],
    'unmonitor frees the identity' => [
        function (Team $team): array {
            Health::for($team)->monitor(ServiceCheck::class)->meta(['service' => 'api'])->save();
            Health::for($team)->unmonitor(ServiceCheck::class);
            $again = Health::for($team)->monitor(ServiceCheck::class)->meta(['service' => 'api'])->save();

            return [$again->wasRecentlyCreated, Health::for($team)->monitors()->count()];
        },
        [true, 1],
    ],
]);

it('gives up the in-memory identity of a schedule whose check is not registered once its meta moves', function (): void {
    $fake = Health::fake();
    $fake->register(new ServiceCheck);
    $team = Team::create();

    $row = Health::for($team)->monitor(ServiceCheck::class)->meta(['service' => 'api'])->save();

    $registry = new ReflectionProperty(HealthManager::class, 'checks');
    $checks = $registry->getValue($fake);
    $registry->setValue($fake, []);

    Health::for($team)->reconfigure($row, new ReconfigureHealthCheckData(meta: ['service' => 'web']));

    $registry->setValue($fake, $checks);

    Health::for($team)->monitor(ServiceCheck::class)->meta(['service' => 'api'])->save();

    expect($row->identity_hash)->toBeNull()
        ->and($row->meta)->toBe(['service' => 'web'])
        ->and(Health::for($team)->monitors())->toHaveCount(2);
});
