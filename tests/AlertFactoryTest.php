<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Actions\RunHealthCheckAction;
use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;

it('builds alerts via the factory states', function () {
    expect(Alert::factory()->warning()->make()->status)->toBe(Status::Warning)
        ->and(Alert::factory()->failed()->make()->status)->toBe(Status::Failed)
        ->and(Alert::factory()->skipped()->make()->status)->toBe(Status::Skipped)
        ->and(Alert::factory()->recovered()->make()->recovered_at)->not->toBeNull();
});

it('opens a factory alert on its health check\'s notifiable, holding the open slot', function () {
    $row = createHealthCheckWithNotifiable(Team::create());

    $alert = Alert::factory()->warning()->create(['health_check_id' => $row->getKey()]);
    $closed = Alert::factory()->recovered()->create(['health_check_id' => $row->getKey()]);

    expect($alert->notifiable_type)->toBe($row->notifiable_type)
        ->and($alert->notifiable_id)->toEqual($row->notifiable_id)
        ->and($alert->open_slot)->toBe(Alert::OPEN_SLOT)
        ->and($closed->open_slot)->toBeNull()
        ->and(Health::report()->checks()[0]->status)->toBe(Status::Warning);

    // The pipeline follows the factory alert instead of opening a second one beside it.
    ExampleHealthCheck::$status = Status::Failed;
    app(RunHealthCheckAction::class)->execute($row);

    expect(Alert::query()->open()->where('health_check_id', $row->getKey())->count())->toBe(1)
        ->and($alert->fresh()?->status)->toBe(Status::Failed);
});

it('takes the notifiable of the health check it creates by default', function () {
    $alert = Alert::factory()->create();

    expect($alert->notifiable_type)->toBe($alert->healthCheck?->notifiable_type)
        ->and($alert->notifiable_id)->toEqual($alert->healthCheck?->notifiable_id);
});
