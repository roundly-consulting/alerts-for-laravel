<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;
use RoundlyConsulting\Alerts\Tests\Models\User;

it('runs a registered ok check and exits zero', function () {
    Health::check(ExampleHealthCheck::class);

    $this->artisan('alerts:check', ['key' => 'example_health_check'])
        ->assertExitCode(0);
});

it('exits non-zero and prints the message for a failing check', function () {
    ExampleHealthCheck::$status = Status::Failed;
    ExampleHealthCheck::$message = 'database is down';
    Health::check(ExampleHealthCheck::class);

    $this->artisan('alerts:check', ['key' => 'example_health_check'])
        ->expectsOutputToContain('database is down')
        ->assertExitCode(1);
});

it('lists registered keys for an unknown check', function () {
    Health::check(ExampleHealthCheck::class);

    $this->artisan('alerts:check', ['key' => 'nope'])
        ->expectsOutputToContain('No registered check matches [nope].')
        ->assertExitCode(1);
});

it('runs the side-effect path with a notifiable', function () {
    Notification::fake();
    ExampleHealthCheck::$status = Status::Failed;
    Health::check(ExampleHealthCheck::class);

    $team = Team::create();
    User::create(['email' => 'a@b.com']);

    $this->artisan('alerts:check', [
        'key' => 'example_health_check',
        '--notifiable' => Team::class.':'.$team->getKey(),
    ])->assertExitCode(1);

    $this->assertDatabaseHas('alerts', ['status' => 'failed']);
    $this->assertDatabaseHas('health_check_runs', ['status' => 'failed']);
});

it('errors on a malformed notifiable option', function () {
    Health::check(ExampleHealthCheck::class);

    $this->artisan('alerts:check', [
        'key' => 'example_health_check',
        '--notifiable' => 'no-colon',
    ])->assertExitCode(1);
});

it('pretty-prints latency and nested meta rows', function () {
    ExampleHealthCheck::$status = Status::Ok;
    ExampleHealthCheck::$meta = ['nested' => ['a' => 1], 'latency_ms' => 42];
    Health::check(ExampleHealthCheck::class);

    $this->artisan('alerts:check', ['key' => 'example_health_check'])
        ->expectsOutputToContain('meta.meta')
        ->assertExitCode(0);
});

it('errors when the notifiable model is not found', function () {
    Health::check(ExampleHealthCheck::class);

    $this->artisan('alerts:check', [
        'key' => 'example_health_check',
        '--notifiable' => Team::class.':9999',
    ])->assertExitCode(1);
});

it('errors when the notifiable class is not an eloquent model', function () {
    Health::check(ExampleHealthCheck::class);

    $this->artisan('alerts:check', [
        'key' => 'example_health_check',
        '--notifiable' => 'NotAModel:1',
    ])->assertExitCode(1);
});

it('prints a throwing check as a failed result on a probe run', function () {
    Health::define('flaky', function (): never {
        throw new RuntimeException('upstream exploded');
    });

    $this->artisan('alerts:check', ['key' => 'flaky'])
        ->expectsOutputToContain('upstream exploded')
        ->assertExitCode(1);
});
