<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\Status\CheckStatus;
use RoundlyConsulting\Alerts\Tests\HealthChecks\AnotherHealthCheck;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;

/**
 * The fake's report follows the real one: a window, scheduled-only, no uptime before a run,
 * and `muted` from the silences (in memory). It records no durations, so its p95 stays null.
 */
beforeEach(function (): void {
    Carbon::setTestNow('2026-10-10 12:00:00');
    Health::checks([ExampleHealthCheck::class, AnotherHealthCheck::class]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('reports no uptime before a run and no p95 at all', function (): void {
    Health::fake();
    $team = Team::create();

    Health::for($team)->monitor(ExampleHealthCheck::class)->save();

    $check = Health::report()->checks()[0];

    expect($check->uptime)->toBeNull()
        ->and($check->p95LatencyMs)->toBeNull();

    Health::for($team)->run(ExampleHealthCheck::class);

    expect(Health::report()->checks()[0]->uptime)->toBe(100.0)
        ->and(Health::report()->checks()[0]->p95LatencyMs)->toBeNull();
});

it('computes uptime only over the runs since a moment', function (): void {
    Health::fake();
    $team = Team::create();

    ExampleHealthCheck::$ok = false;
    Health::for($team)->run(ExampleHealthCheck::class);

    $this->travel(2)->days();

    ExampleHealthCheck::$ok = true;
    Health::for($team)->run(ExampleHealthCheck::class);

    expect(Health::report()->checks()[0]->uptime)->toBe(50.0)
        ->and(Health::report(since: now()->subDay())->checks()[0]->uptime)->toBe(100.0)
        ->and(Health::for($team)->report(since: now()->addMinute())->checks()[0]->uptime)->toBeNull();
});

it('drops on-demand monitors from a scheduled-only report', function (): void {
    Health::fake();
    $team = Team::create();

    Health::for($team)->monitor(ExampleHealthCheck::class)->save();
    Health::for($team)->run(AnotherHealthCheck::class);

    expect(array_map(fn (CheckStatus $check): string => $check->key, Health::report(scheduledOnly: true)->checks()))
        ->toBe(['example_health_check'])
        ->and(Health::for($team)->report(scheduledOnly: true)->checks())->toHaveCount(1)
        ->and(Health::report()->checks())->toHaveCount(2);
});

it('flags muted from the silences, with or without an open alert', function (): void {
    Health::fake();
    $team = Team::create();

    Health::for($team)->monitor(ExampleHealthCheck::class)->tags(['db'])->save();

    expect(Health::report()->checks()[0]->muted)->toBeFalse();

    Health::silences()->mute('db', for: $team);

    expect(Health::report()->checks()[0]->muted)->toBeTrue();

    ExampleHealthCheck::$ok = false;
    Health::for($team)->run(ExampleHealthCheck::class);

    expect(Health::report()->checks()[0]->muted)->toBeTrue()
        ->and(Health::report()->checks()[0]->meta)->toMatchArray(['muted' => true]);

    Health::silences()->unmute('db', $team);

    expect(Health::report()->checks()[0]->muted)->toBeFalse();
});
