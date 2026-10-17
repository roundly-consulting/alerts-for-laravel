<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\Alerts\AlertSilence;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\HealthCheckRun;
use RoundlyConsulting\Alerts\Tests\Models\CustomAlert;
use RoundlyConsulting\Alerts\Tests\Models\CustomAlertSilence;
use RoundlyConsulting\Alerts\Tests\Models\CustomHealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\CustomHealthCheckRun;

/**
 * The shipped factories build the model the host configured. A factory without a `modelName()`
 * override builds its packaged `$model`, so a host seeding through `HealthCheck::factory()` got
 * packaged rows — without its own casts, events or observers. Exact classes, not `instanceof`:
 * the custom models extend the packaged ones, so `toBeInstanceOf` passes either way.
 */
it('builds the configured health check and run models', function (): void {
    expect(HealthCheck::factory()->create()::class)->toBe(CustomHealthCheck::class)
        ->and(CustomHealthCheck::factory()->create()::class)->toBe(CustomHealthCheck::class)
        ->and(HealthCheckRun::factory()->create()::class)->toBe(CustomHealthCheckRun::class);
});

it('builds the configured alert and silence models', function (): void {
    expect(Alert::factory()->create()::class)->toBe(CustomAlert::class)
        ->and(AlertSilence::factory()->create()::class)->toBe(CustomAlertSilence::class);
});

it('fires the host model events for the health check a run factory creates', function (): void {
    // Reading `$run->healthCheck` back resolves through the seam either way, so the oracle is
    // the host class's own `created` event: it fires only for a row created as that class.
    CustomHealthCheck::resetCreationCount();

    HealthCheckRun::factory()->create();
    Alert::factory()->create();

    expect(CustomHealthCheck::creationCount())->toBe(2);
});
