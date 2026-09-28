<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Tests\Fixtures\SwappedModelsTestCase;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;
use RoundlyConsulting\Alerts\Tests\TestCase;

// Explicit paths, not `->in(__DIR__)`: the Configured directory below needs a different
// base case, and a blanket bind would claim it first — Pest binds a test case per
// directory, not per file. ArchTest.php is listed because `swappableModelsAreNotFinal`
// reads the four `alerts.…` config defaults and so needs the app booted; an arch file is
// not automatically test-cased.
uses(TestCase::class)->in(
    'ArchTest.php',
    'AlertableTest.php',
    'AlertFactoryTest.php',
    'AlertScopesTest.php',
    'AlertTest.php',
    'CheckResultTest.php',
    'HealthBuilderTest.php',
    'HealthCheckTest.php',
    'HealthRunTest.php',
    'HealthTest.php',
    'SchedulingTest.php',
    'Actions',
    'Checks',
    'Commands',
    'Concurrency',
    'DataTransferObjects',
    'Enums',
    'Escalation',
    'Feature',
    'Flap',
    'History',
    'Http',
    'Jobs',
    'Maintenance',
    'Migrations',
    'Notifications',
    'Provider',
    'Recovery',
    'Routing',
    'Status',
    'Support',
    'Tags',
    'Testing',
    'Traits',
);

// The model-swap proofs need all four `alerts.…` model keys pointed at host subclasses
// BEFORE the providers boot, so they run on their own base case in their own directory.
uses(SwappedModelsTestCase::class)->in('Configured');

uses()->beforeEach(function (): void {
    ExampleHealthCheck::$ok = true;
    ExampleHealthCheck::$status = null;
    ExampleHealthCheck::$message = 'Everything seems ok';
    ExampleHealthCheck::$meta = ['checked' => true];
})->in(__DIR__);

function createHealthCheckWithNotifiable(
    ?object $notifiable = null,
    ?string $healthCheckKey = null,
    string $frequency = '* * * * *',
    int $maxAttempts = 1,
    int $decayMinutes = 1,
    array $meta = [],
): HealthCheck {
    if (is_null($notifiable)) {
        $notifiable = Team::create();
    }

    if (is_null($healthCheckKey)) {
        Health::check(ExampleHealthCheck::class);

        $healthCheckKey = 'example_health_check';
    }

    return HealthCheck::create([
        'notifiable_type' => $notifiable->getMorphClass(),
        'notifiable_id' => $notifiable->getKey(),
        'health_check' => $healthCheckKey,
        'frequency' => $frequency,
        'max_attempts' => $maxAttempts,
        'decay_minutes' => $decayMinutes,
        'meta' => $meta,
    ]);
}

function createAlertForHealthCheck(HealthCheck $healthCheck, bool $recovered = false): Alert
{
    return Alert::create([
        'notifiable_type' => $healthCheck->notifiable_type,
        'notifiable_id' => $healthCheck->notifiable_id,
        'health_check_id' => $healthCheck->getKey(),
        'triggered_at' => now(),
        'recovered_at' => $recovered ? now() : null,
    ]);
}
