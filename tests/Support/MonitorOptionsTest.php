<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Support\MonitorOptions;

it('returns sensible defaults for empty meta', function () {
    $options = MonitorOptions::fromMeta(null);

    expect($options->failAfter())->toBe(1)
        ->and($options->recoverAfter())->toBe(1)
        ->and($options->timeout())->toBeNull()
        ->and($options->notifyVia())->toBeNull()
        ->and($options->notifyViaLevels())->toBe([])
        ->and($options->escalation())->toBe([]);
});

it('treats malformed option values defensively', function () {
    $options = MonitorOptions::fromMeta([
        MonitorOptions::NOTIFY_VIA => 'not-an-array',
        MonitorOptions::NOTIFY_VIA_LEVELS => 'nope',
        MonitorOptions::ESCALATION => 'nope',
    ]);

    expect($options->notifyVia())->toBeNull()
        ->and($options->notifyViaLevels())->toBe([])
        ->and($options->escalation())->toBe([]);
});

it('sorts the escalation policy by threshold', function () {
    $options = MonitorOptions::fromMeta([
        MonitorOptions::ESCALATION => [5 => 'oncall', 1 => 'owner', 3 => 'team'],
    ]);

    expect(array_keys($options->escalation()))->toBe([1, 3, 5]);
});

it('computes the level for a failure count', function () {
    $options = MonitorOptions::fromMeta([
        MonitorOptions::ESCALATION => [1 => 'owner', 3 => 'team', 5 => 'oncall'],
    ]);

    expect($options->levelForFailures(0))->toBe(0)
        ->and($options->levelForFailures(2))->toBe(1)
        ->and($options->levelForFailures(4))->toBe(3)
        ->and($options->levelForFailures(9))->toBe(5);
});

it('returns the groups newly reached between two levels', function () {
    $options = MonitorOptions::fromMeta([
        MonitorOptions::ESCALATION => [1 => 'owner', 3 => 'team', 5 => 'oncall'],
    ]);

    expect($options->groupsBetween(0, 3))->toBe(['owner', 'team'])
        ->and($options->groupsBetween(1, 5))->toBe(['team', 'oncall'])
        ->and($options->groupsBetween(5, 5))->toBe([]);
});

it('coerces non-array level channel entries away', function () {
    $options = MonitorOptions::fromMeta([
        MonitorOptions::NOTIFY_VIA_LEVELS => [3 => ['slack'], 4 => 'bad'],
    ]);

    expect($options->notifyViaLevels())->toBe([3 => ['slack']]);
});

it('accepts a policy keyed by thresholds of at least one and refuses any other key', function () {
    expect(MonitorOptions::validateEscalation([1 => 'owner', 3 => 'team']))->toBe([1 => 'owner', 3 => 'team'])
        ->and(fn () => MonitorOptions::validateEscalation(['owner' => 'team']))
        ->toThrow(RoundlyConsulting\Alerts\Exceptions\InvalidHealthCheck::class, 'Escalation threshold [owner]');
});
