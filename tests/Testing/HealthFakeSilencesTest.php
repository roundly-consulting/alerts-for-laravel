<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Alerts\Exceptions\InvalidRetention;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;

/**
 * The fake answers the 1.2 silence API from memory the way the real manager answers it
 * from the database: one silence per identity, snapshots and pruning.
 */
beforeEach(function (): void {
    Carbon::setTestNow('2026-10-10 12:00:00');
    Health::check(ExampleHealthCheck::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function fakeRow(Team $team, array $tags = []): HealthCheck
{
    return (new HealthCheck)->forceFill([
        'notifiable_type' => $team->getMorphClass(),
        'notifiable_id' => $team->getKey(),
        'health_check' => 'example_health_check',
        'frequency' => '* * * * *',
        'tags' => $tags === [] ? null : $tags,
    ]);
}

it('keeps one silence per identity and records every mute', function (): void {
    $fake = Health::fake();
    $team = Team::create();

    $first = Health::silences()->mute('db', until: now()->addHour(), reason: 'deploy');
    $second = Health::silences()->mute('db', until: now()->addHours(3), reason: 'migration');
    Health::silences()->mute('db', for: $team);

    expect($second)->toBe($first)
        ->and($first->reason)->toBe('migration')
        ->and($first->ends_at?->toDateTimeString())->toBe('2026-10-10 15:00:00')
        ->and(Health::silences()->active())->toHaveCount(2);

    $fake->assertMuted('db');
    $fake->assertMuted('db', $team);
    $this->assertDatabaseEmpty('alert_silences');
});

it('snapshots the silences in memory with the runner\'s rule', function (): void {
    Health::fake();
    $team = Team::create();
    $other = Team::create();

    Health::silences()->mute('db', for: $team);
    Health::silences()->mute('*', until: now()->addMinute(), for: $other);

    $snapshot = Health::silences()->snapshot();

    expect($snapshot->covers(fakeRow($team, ['db'])))->toBeTrue()
        ->and($snapshot->covers(fakeRow($team)))->toBeFalse()
        ->and($snapshot->covers(fakeRow($other)))->toBeTrue()
        ->and(Health::silences()->covers(fakeRow($other)))->toBeTrue()
        ->and(Health::silences()->snapshot(now()->addMinutes(5))->covers(fakeRow($other)))->toBeFalse();

    config()->set('alerts.silence', false);

    expect(Health::silences()->snapshot()->isEmpty())->toBeTrue();
});

it('prunes ended silences from memory and records the prune', function (): void {
    $fake = Health::fake();

    Health::silences()->mute('old', until: now()->addMinute());
    Health::silences()->mute('open');

    Carbon::setTestNow('2026-10-20 12:00:00');

    expect(Health::silences()->prune(30))->toBe(0)
        ->and(Health::silences()->prune())->toBe(1);

    $fake->assertSilencesPruned();
    $fake->assertSilencesPruned(30);

    Health::silences()->mute('old');
    $fake->assertMuted('old');
});

it('refuses a silence prune below one day under the fake, recording nothing', function (): void {
    $fake = Health::fake();

    expect(fn () => Health::silences()->prune(0))->toThrow(InvalidRetention::class);

    $fake->assertNoSilencesPruned();
});

it('records the silence prune the command runs', function (): void {
    $fake = Health::fake();

    $this->artisan('alerts:prune-silences', ['--days' => 3])->assertSuccessful();

    $fake->assertSilencesPruned(3);
});

it('fails assertSilencesPruned when nothing or another retention was pruned', function (Closure $act, Closure $assert): void {
    $fake = Health::fake();

    $act();

    $assert($fake);
})->with([
    'nothing pruned' => [fn () => null, fn ($fake) => $fake->assertSilencesPruned()],
    'another retention' => [fn () => Health::silences()->prune(7), fn ($fake) => $fake->assertSilencesPruned(30)],
    'assertNoSilencesPruned' => [fn () => Health::silences()->prune(), fn ($fake) => $fake->assertNoSilencesPruned()],
])->throws(ExpectationFailedException::class);

it('mutes the fake\'s runs with the shared rule, by row tag and check tag', function (): void {
    $fake = Health::fake();
    $team = Team::create();
    Health::define('tagged', fn (): bool => false)->tags(['infra']);
    ExampleHealthCheck::$ok = false;

    Health::silences()->mute('infra', for: $team);
    Health::for($team)->run('tagged');

    Health::for($team)->monitor(ExampleHealthCheck::class)->tags(['db'])->save();
    Health::silences()->mute('db');
    Health::for($team)->run(ExampleHealthCheck::class);

    $fake->assertNothingAlerted();

    Health::for(Team::create())->run('tagged');

    $fake->assertAlerted('tagged');
});

it('ignores the silences in memory for a run while silences are switched off', function (): void {
    $fake = Health::fake();
    $team = Team::create();
    ExampleHealthCheck::$ok = false;

    Health::silences()->mute('*');
    config()->set('alerts.silence', false);

    Health::for($team)->run(ExampleHealthCheck::class);

    $fake->assertAlerted('example_health_check');
});
