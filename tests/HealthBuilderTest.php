<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\CheckResult;
use RoundlyConsulting\Alerts\Checks\ClosureCheck;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\Notifications\HealthCheckFailedNotification;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleNotification;

it('registers and runs a closure check returning a result', function () {
    Health::define('redis-up', fn () => CheckResult::failed('Redis down'))
        ->name('Redis')
        ->description('Pings Redis');

    $check = Health::find('redis-up');

    expect($check)->toBeInstanceOf(ClosureCheck::class)
        ->and($check->name())->toBe('Redis')
        ->and($check->description())->toBe('Pings Redis')
        ->and($check->check()->message)->toBe('Redis down');
});

it('normalises a boolean closure result', function () {
    Health::define('bool-up', fn () => true);
    Health::define('bool-down', fn () => false);

    expect(Health::find('bool-up')->check()->isOk)->toBeTrue()
        ->and(Health::find('bool-down')->check()->isOk)->toBeFalse();
});

it('falls back to the base name and description', function () {
    Health::define('plain', fn () => CheckResult::ok());

    $check = Health::find('plain');

    expect($check->name())->toBe('Closure Check')
        ->and($check->description())->toContain('Closure Check');
});

it('uses the default notification when none is configured', function () {
    Health::define('plain', fn () => CheckResult::ok());

    expect(Health::find('plain')->notification(new stdClass))
        ->toBeInstanceOf(HealthCheckFailedNotification::class);
});

it('uses a class-string notification', function () {
    Health::define('with-class', fn () => CheckResult::failed())
        ->notifyUsing(ExampleNotification::class);

    expect(Health::find('with-class')->notification(new stdClass))
        ->toBeInstanceOf(ExampleNotification::class);
});

it('uses a closure notification', function () {
    Health::define('with-closure', fn () => CheckResult::failed())
        ->notifyUsing(fn ($check, $notifiable) => new ExampleNotification($notifiable));

    expect(Health::find('with-closure')->notification(new stdClass))
        ->toBeInstanceOf(ExampleNotification::class);
});

it('applies a throttle override to the closure check', function () {
    Health::define('throttled', fn () => CheckResult::failed())
        ->throttle(maxAttempts: 5, decayMinutes: 30);

    expect(Health::find('throttled'))->toBeInstanceOf(ClosureCheck::class);
});

it('falls back to the default notification when a class is not a notification', function () {
    Health::define('bad-class', fn () => CheckResult::failed())
        ->notifyUsing(stdClass::class);

    expect(Health::find('bad-class')->notification(new stdClass))
        ->toBeInstanceOf(HealthCheckFailedNotification::class);
});

it('falls back to the default notification when a closure returns a non-notification', function () {
    Health::define('bad-closure', fn () => CheckResult::failed())
        ->notifyUsing(fn () => new stdClass);

    expect(Health::find('bad-closure')->notification(new stdClass))
        ->toBeInstanceOf(HealthCheckFailedNotification::class);
});

it('rebinds throttle from the persisted health check row', function () {
    $team = RoundlyConsulting\Alerts\Tests\Models\Team::create();
    Health::define('bound', fn () => CheckResult::ok());

    $row = $team->monitorCheck('bound')->throttle(maxAttempts: 4, decayMinutes: 7)->save();

    expect($row->healthCheck())->toBeInstanceOf(ClosureCheck::class);
});
