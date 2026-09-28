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
    $health = resolve(RoundlyConsulting\Alerts\HealthManager::class);
    expect($health->find('example_health_check'))->toBeNull();

    $health->check(ExampleHealthCheck::class);

    expect(Health::find('example_health_check'))
        ->toBeInstanceOf(ExampleHealthCheck::class);
});

it('registers one built-in check several times under distinct keys', function () {
    Illuminate\Support\Facades\Http::fake();

    Health::check(RoundlyConsulting\Alerts\Checks\HttpPingCheck::make('https://api.example.com')->as('api_ping'));
    Health::check(RoundlyConsulting\Alerts\Checks\HttpPingCheck::make('https://www.example.com')->as('site_ping'));

    expect(Health::all()->keys()->all())->toBe(['api_ping', 'site_ping'])
        ->and(Health::find('api_ping')->check()->meta['url'])->toBe('https://api.example.com')
        ->and(Health::find('site_ping')->check()->meta['url'])->toBe('https://www.example.com')
        ->and(Health::find('site_ping')->name())->toBe('Site Ping');
});

it('keeps a custom key through a row binding', function () {
    $check = RoundlyConsulting\Alerts\Checks\CacheCheck::make('array')->as('array_cache');

    expect($check->withHealthCheck(createHealthCheckWithNotifiable())->key())->toBe('array_cache')
        ->and(RoundlyConsulting\Alerts\Checks\CacheCheck::make()->key())->toBe('cache_check');
});
