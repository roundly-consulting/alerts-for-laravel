<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\CheckResult;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\Support\MonitorOptions;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;

it('persists every declarative option onto the scheduled row', function () {
    Health::check(ExampleHealthCheck::class);

    $team = Team::create();

    $row = $team->monitorCheck(ExampleHealthCheck::class)
        ->everyFiveMinutes()
        ->failAfter(3)
        ->recoverAfter(2)
        ->timeout(5)
        ->tags(['critical', 'db'])
        ->notifyVia(['mail', 'slack'])
        ->notifyVia(['sms'], level: 3)
        ->escalate([1 => 'owner', 3 => 'team'])
        ->throttle(maxAttempts: 3, decayMinutes: 10)
        ->save();

    $options = $row->refresh()->options();

    expect($row->tags)->toBe(['critical', 'db'])
        ->and($options->failAfter())->toBe(3)
        ->and($options->recoverAfter())->toBe(2)
        ->and($options->timeout())->toBe(5)
        ->and($options->notifyVia())->toBe(['mail', 'slack'])
        ->and($options->notifyViaLevels())->toBe([3 => ['sms']])
        ->and($options->escalation())->toBe([1 => 'owner', 3 => 'team']);
});

it('mirrors the declarative options on the inline closure builder', function () {
    $pending = Health::define('redis-up', fn () => CheckResult::ok())
        ->failAfter(2)
        ->recoverAfter(1)
        ->tags(['cache'])
        ->notifyVia(['slack'])
        ->notifyVia(['sms'], level: 2)
        ->escalate([2 => 'oncall'])
        ->timeout(3);

    $meta = $pending->resolvedMeta();

    expect($pending->resolvedTags())->toBe(['cache'])
        ->and($meta[MonitorOptions::FAIL_AFTER])->toBe(2)
        ->and($meta[MonitorOptions::TIMEOUT])->toBe(3)
        ->and($meta[MonitorOptions::NOTIFY_VIA])->toBe(['slack'])
        ->and($meta[MonitorOptions::NOTIFY_VIA_LEVELS])->toBe([2 => ['sms']])
        ->and($meta[MonitorOptions::ESCALATION])->toBe([2 => 'oncall']);
});

it('seeds an ad-hoc row from inline closure options on Health::run', function () {
    $team = Team::create();

    Health::define('flaky', fn () => CheckResult::failed('down'))
        ->failAfter(5)
        ->tags(['inline']);

    Health::run(Health::find('flaky'), $team);

    $row = $team->healthChecks()->where('health_check', 'flaky')->first();

    expect($row->tags)->toBe(['inline'])
        ->and($row->options()->failAfter())->toBe(5);
});

it('keeps consumer meta alongside declarative options', function () {
    Health::check(ExampleHealthCheck::class);

    $team = Team::create();

    $row = $team->monitorCheck(ExampleHealthCheck::class)
        ->meta(['custom' => 'value'])
        ->failAfter(2)
        ->save();

    expect($row->refresh()->meta['custom'])->toBe('value')
        ->and($row->options()->failAfter())->toBe(2);
});
