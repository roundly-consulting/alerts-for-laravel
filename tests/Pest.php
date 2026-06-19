<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;
use RoundlyConsulting\Alerts\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

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
