<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;

it('records runs without sending notifications', function () {
    Notification::fake();
    $fake = Health::fake();

    ExampleHealthCheck::$ok = false;

    Health::run(ExampleHealthCheck::class, Team::create());

    $fake->assertChecked('example_health_check');
    $fake->assertAlerted('example_health_check');

    Notification::assertNothingSent();
    $this->assertDatabaseEmpty('alerts');
});

it('records recoveries for healthy checks', function () {
    $fake = Health::fake();

    Health::run(ExampleHealthCheck::class, Team::create());

    $fake->assertRecovered('example_health_check');
    $fake->assertNothingAlerted();
});

it('fails assertChecked when the check did not run', function () {
    $fake = Health::fake();

    $fake->assertChecked('missing');
})->throws(ExpectationFailedException::class);

it('fails assertAlerted when nothing alerted', function () {
    $fake = Health::fake();

    $fake->assertAlerted('missing');
})->throws(ExpectationFailedException::class);

it('fails assertRecovered when nothing recovered', function () {
    $fake = Health::fake();

    $fake->assertRecovered('missing');
})->throws(ExpectationFailedException::class);

it('fails assertNothingAlerted when an alert was recorded', function () {
    $fake = Health::fake();
    ExampleHealthCheck::$ok = false;

    Health::run(ExampleHealthCheck::class, Team::create());

    $fake->assertNothingAlerted();
})->throws(ExpectationFailedException::class);

it('fails assertNothingRecovered when a recovery was recorded', function () {
    $fake = Health::fake();

    Health::run(ExampleHealthCheck::class, Team::create());

    $fake->assertNothingRecovered();
})->throws(ExpectationFailedException::class);
