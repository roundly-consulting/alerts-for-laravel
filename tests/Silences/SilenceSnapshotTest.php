<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Alerts\Actions\RunHealthCheckAction;
use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\Alerts\AlertSilence;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Support\MonitorOptions;
use RoundlyConsulting\Alerts\Support\SilenceSnapshot;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;

/**
 * `Health::silences()->snapshot()` answers "is this row muted?" with the runner's own rule —
 * the row's key, its effective tags and `*`, scoped globally or to the row's own notifiable —
 * for any number of rows from one query.
 */
beforeEach(function (): void {
    Carbon::setTestNow('2026-10-10 12:00:00');

    Health::check(ExampleHealthCheck::class);
    Health::define('tagged', fn (): bool => true)->tags(['infra']);

    $this->team = Team::create();
    $this->other = Team::create();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function snapshotRow(Team $team, string $check = 'example_health_check', array $tags = []): HealthCheck
{
    return HealthCheck::create([
        'notifiable_type' => $team->getMorphClass(),
        'notifiable_id' => $team->getKey(),
        'health_check' => $check,
        'frequency' => '* * * * *',
        'tags' => $tags === [] ? null : $tags,
        'meta' => [],
    ]);
}

it('covers a row by its key, a row tag, a check tag or the global key', function (string $key, string $check, array $tags): void {
    $row = snapshotRow($this->team, $check, $tags);

    expect(Health::silences()->covers($row))->toBeFalse();

    Health::silences()->mute($key);

    expect(Health::silences()->snapshot()->covers($row))->toBeTrue()
        ->and(Health::silences()->covers($row))->toBeTrue();
})->with([
    'key' => ['example_health_check', 'example_health_check', []],
    'row tag' => ['db', 'example_health_check', ['db']],
    'check tag' => ['infra', 'tagged', []],
    'global' => ['*', 'example_health_check', []],
]);

it('covers a row only for its own notifiable or a global silence', function (): void {
    $mine = snapshotRow($this->team);
    $theirs = snapshotRow($this->other);

    Health::silences()->mute('example_health_check', for: $this->team);

    $snapshot = Health::silences()->snapshot();

    expect($snapshot->covers($mine))->toBeTrue()
        ->and($snapshot->covers($theirs))->toBeFalse();
});

it('does not cover a row with a silence on another key or tag', function (): void {
    $row = snapshotRow($this->team, tags: ['db']);

    Health::silences()->mute('cache');
    Health::silences()->mute('another_check');

    expect(Health::silences()->snapshot()->covers($row))->toBeFalse()
        ->and(Health::silences()->snapshot()->isEmpty())->toBeFalse();
});

it('reads the silences in force at the moment it is taken for', function (): void {
    $row = snapshotRow($this->team);

    AlertSilence::factory()->create(['key' => '*', 'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2)]);
    AlertSilence::factory()->create(['key' => 'example_health_check', 'ends_at' => now()->subMinute()]);

    expect(Health::silences()->snapshot()->covers($row))->toBeFalse()
        ->and(Health::silences()->snapshot()->isEmpty())->toBeTrue()
        ->and(Health::silences()->snapshot(now()->addMinutes(90))->covers($row))->toBeTrue()
        ->and(Health::silences()->snapshot(now()->subMinutes(5))->covers($row))->toBeTrue();
});

it('is empty while silences are switched off', function (): void {
    $row = snapshotRow($this->team);
    Health::silences()->mute('*');

    config()->set('alerts.silence', false);

    expect(Health::silences()->snapshot()->isEmpty())->toBeTrue()
        ->and(Health::silences()->covers($row))->toBeFalse();
});

it('loads every silence it needs in one query, whatever the number of rows', function (): void {
    $rows = collect(range(1, 50))->map(fn (int $i): HealthCheck => snapshotRow(Team::create(), tags: ["t{$i}"]));

    foreach (range(1, 10) as $i) {
        Health::silences()->mute("t{$i}");
    }

    DB::enableQueryLog();
    DB::flushQueryLog();

    $snapshot = Health::silences()->snapshot();
    $covered = $rows->filter(fn (HealthCheck $row): bool => $snapshot->covers($row))->count();

    expect(DB::getQueryLog())->toHaveCount(1)
        ->and($covered)->toBe(10);
});

it('is built empty by hand', function (): void {
    expect((new SilenceSnapshot)->isEmpty())->toBeTrue()
        ->and((new SilenceSnapshot)->covers(snapshotRow($this->team)))->toBeFalse();
});

/*
 * Parity: the snapshot and the runner's mute gate are one rule. Each scenario mutes, runs a
 * failing check through the real pipeline, and compares the snapshot's verdict with whether
 * the pipeline treated the run as muted (it flags the alert it opens).
 */
it('agrees with the runner on whether a row is muted', function (Closure $silence): void {
    Notification::fake();
    ExampleHealthCheck::$ok = false;

    $row = snapshotRow($this->team, tags: ['db']);
    $silence($this->team, $this->other);

    $covered = Health::silences()->snapshot()->covers($row);

    app(RunHealthCheckAction::class)->execute($row->fresh());

    $mutedByRunner = (bool) (Alert::query()->sole()->meta[MonitorOptions::MUTED] ?? false);

    expect($covered)->toBe($mutedByRunner);
})->with([
    'nothing' => [fn () => null],
    'key' => [fn () => Health::silences()->mute('example_health_check')],
    'row tag' => [fn () => Health::silences()->mute('db')],
    'global' => [fn () => Health::silences()->mute('*')],
    'own notifiable' => [fn (Team $team) => Health::silences()->mute('db', for: $team)],
    'other notifiable' => [fn (Team $team, Team $other) => Health::silences()->mute('*', for: $other)],
    'other key' => [fn () => Health::silences()->mute('cache')],
    'ended' => [fn () => AlertSilence::factory()->create(['key' => '*', 'ends_at' => now()->subSecond()])],
    'not started' => [fn () => AlertSilence::factory()->create(['key' => '*', 'starts_at' => now()->addSecond()])],
]);
