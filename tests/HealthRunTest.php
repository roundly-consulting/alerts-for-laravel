<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Alerts\CheckResult;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Events\HealthCheckFailed;
use RoundlyConsulting\Alerts\Events\HealthCheckRecovered;
use RoundlyConsulting\Alerts\Exceptions\InvalidHealthCheck;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleNotification;
use RoundlyConsulting\Alerts\Tests\Models\Team;
use RoundlyConsulting\Alerts\Tests\Models\User;

it('runs a check synchronously and opens an alert', function () {
    ExampleHealthCheck::$ok = false;
    Notification::fake();
    Event::fake();

    $team = Team::create();
    User::create(['email' => 'john@doe.com']);

    $result = Health::for($team)->run(ExampleHealthCheck::class);

    expect($result)->toBeInstanceOf(CheckResult::class)
        ->and($result->status)->toBe(Status::Failed);

    $this->assertDatabaseHas('alerts', [
        'notifiable_type' => $team->getMorphClass(),
        'notifiable_id' => $team->getKey(),
    ]);

    Notification::assertSentTo(User::first(), ExampleNotification::class);
    Event::assertDispatched(HealthCheckFailed::class);
});

it('recovers an alert when a previously failing check passes', function () {
    Event::fake();
    $team = Team::create();

    ExampleHealthCheck::$ok = false;
    Health::for($team)->run(ExampleHealthCheck::class);

    ExampleHealthCheck::$ok = true;
    Health::for($team)->run(ExampleHealthCheck::class);

    Event::assertDispatched(HealthCheckRecovered::class);
    expect($team->alerts()->whereNotNull('recovered_at')->count())->toBe(1);
});

it('accepts a check instance', function () {
    $team = Team::create();

    $result = Health::for($team)->run(new ExampleHealthCheck);

    expect($result->isOk)->toBeTrue();
});

it('rejects a class that is not a check', function () {
    Health::for(Team::create())->run(stdClass::class);
})->throws(InvalidHealthCheck::class);

it('reads the overall status via the facade', function () {
    Health::check(ExampleHealthCheck::class);
    $team = Team::create();
    $team->createHealthCheck('example_health_check', '* * * * *');

    expect(Health::status())->toBe(Status::Ok);
});

it('survives a failure message longer than any varchar column', function () {
    Notification::fake();

    $team = Team::create();
    $long = 'SQLSTATE[08006] connection failed: '.str_repeat('x', 5000);

    Health::define('flaky', function () use ($long): never {
        throw new RuntimeException($long);
    });

    $result = Health::for($team)->run(Health::find('flaky'));

    // The caller still gets the whole text; only what is persisted is bounded.
    expect($result->status)->toBe(Status::Failed)
        ->and($result->message)->toBe($long)
        ->and(mb_strlen((string) RoundlyConsulting\Alerts\Alert::sole()->message))
        ->toBeLessThanOrEqual(CheckResult::MAX_STORED_MESSAGE_LENGTH)
        ->and(mb_strlen((string) RoundlyConsulting\Alerts\HealthCheckRun::sole()->message))
        ->toBeLessThanOrEqual(CheckResult::MAX_STORED_MESSAGE_LENGTH)
        ->and(RoundlyConsulting\Alerts\HealthCheckRun::sole()->meta['exception_message'])->toBe($long);
});

it('runs an inline check by its registered key', function () {
    Notification::fake();
    $team = Team::create();

    Health::define('redis-up', fn () => CheckResult::failed('Redis down'));

    $result = Health::for($team)->run('redis-up');

    expect($result->status)->toBe(Status::Failed)
        ->and($team->healthChecks()->sole()->health_check)->toBe('redis-up');
});

it('refuses a string that is neither a registered key nor a check class', function () {
    Health::for(Team::create())->run('nothing-registered');
})->throws(InvalidHealthCheck::class, 'No health check is registered for key [nothing-registered]');

it('runs the configured instance for a class-string and never replaces it', function () {
    Illuminate\Support\Facades\Http::fake();
    Notification::fake();
    $team = Team::create();

    Health::check(RoundlyConsulting\Alerts\Checks\HttpPingCheck::make('https://api.example.com'));
    $registered = Health::find('http_ping_check');

    $result = Health::for($team)->run(RoundlyConsulting\Alerts\Checks\HttpPingCheck::class);

    expect($result->meta['url'])->toBe('https://api.example.com')
        ->and(Health::find('http_ping_check'))->toBe($registered);
});

it('runs a passed instance without replacing the registered one', function () {
    Illuminate\Support\Facades\Http::fake();
    Notification::fake();
    $team = Team::create();

    Health::check(RoundlyConsulting\Alerts\Checks\HttpPingCheck::make('https://api.example.com'));
    $registered = Health::find('http_ping_check');

    $adHoc = Health::for($team)->run(RoundlyConsulting\Alerts\Checks\HttpPingCheck::make('https://other.example.com'));
    $scheduled = Health::for($team)->run($team->healthChecks()->sole());

    expect($adHoc->meta['url'])->toBe('https://other.example.com')
        ->and(Health::find('http_ping_check'))->toBe($registered)
        // a later run of the row (as a queue worker would) probes the configured target
        ->and($scheduled->meta['url'])->toBe('https://api.example.com');
});

it('refuses a class-string registered several times under different keys', function () {
    Health::check(RoundlyConsulting\Alerts\Checks\HttpPingCheck::make('https://a.example.com')->as('a_ping'));
    Health::check(RoundlyConsulting\Alerts\Checks\HttpPingCheck::make('https://b.example.com')->as('b_ping'));

    Health::for(Team::create())->run(RoundlyConsulting\Alerts\Checks\HttpPingCheck::class);
})->throws(InvalidHealthCheck::class, 'run it by key: a_ping, b_ping');

it('runs the single custom-keyed registration of a class-string', function () {
    Illuminate\Support\Facades\Http::fake();
    Notification::fake();

    Health::check(RoundlyConsulting\Alerts\Checks\HttpPingCheck::make('https://a.example.com')->as('a_ping'));

    $team = Team::create();
    $result = Health::for($team)->run(RoundlyConsulting\Alerts\Checks\HttpPingCheck::class);

    expect($result->meta['url'])->toBe('https://a.example.com')
        ->and($team->healthChecks()->sole()->health_check)->toBe('a_ping');
});
