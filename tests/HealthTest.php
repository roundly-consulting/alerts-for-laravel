<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\Exceptions\InvalidHealthCheck;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\Tests\HealthChecks\AnotherHealthCheck;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;

it('registers single health check into memory', function () {
    Health::check(ExampleHealthCheck::class);

    expect(Health::all())
        ->toBeInstanceOf(Collection::class)
        ->toHaveCount(1)
        ->first()->key()->toBe('example_health_check');
});

it('registers multiple health checks into memory at once', function () {
    Health::checks([ExampleHealthCheck::class, AnotherHealthCheck::class]);

    expect(Health::all())
        ->toBeInstanceOf(Collection::class)
        ->toHaveCount(2)
        ->first()->key()->toBe('example_health_check')
        ->last()->key()->toBe('another_health_check');
});

it('throws exception when invalid health check is provided to registration', function () {
    class InvalidCheck {}

    Health::check(InvalidCheck::class);
})->throws(InvalidHealthCheck::class, 'Class [InvalidCheck] does not extend base check class ['.Check::class.']');

it('finds health check in memory or returns null', function () {
    Health::check(ExampleHealthCheck::class);

    expect(Health::find('example_health_check'))
        ->toBeInstanceOf(ExampleHealthCheck::class)
        ->and(Health::find('not_existing'))
        ->toBeNull();
});

it('uses singleton and can be accessed via facade', function () {
    $health = resolve(RoundlyConsulting\Alerts\Health::class);
    expect($health->find('example_health_check'))->toBeNull();

    $health->check(ExampleHealthCheck::class);

    expect(Health::find('example_health_check'))
        ->toBeInstanceOf(ExampleHealthCheck::class);
});
