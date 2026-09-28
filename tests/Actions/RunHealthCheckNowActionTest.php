<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Actions\RunHealthCheckNowAction;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Exceptions\InvalidHealthCheck;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;

it('seeds one ad-hoc row per notifiable and check, then reuses it', function (): void {
    Health::check(ExampleHealthCheck::class);
    $team = Team::create();
    $action = app(RunHealthCheckNowAction::class);

    $action->execute($team, new ExampleHealthCheck);
    ExampleHealthCheck::$ok = false;
    $result = $action->execute($team, new ExampleHealthCheck);

    $row = HealthCheck::sole();

    expect($result->status)->toBe(Status::Failed)
        ->and($row->isScheduledFor($team))->toBeTrue()
        ->and($row->runs()->count())->toBe(2)
        ->and($row->consecutive_failures)->toBe(1);
});

it('runs a given row of the notifiable and refuses another\'s', function (): void {
    $team = Team::create();
    $row = createHealthCheckWithNotifiable($team);
    $action = app(RunHealthCheckNowAction::class);

    expect($action->execute($team, $row)->isOk)->toBeTrue()
        ->and($row->runs()->count())->toBe(1);

    $action->execute(Team::create(), $row);
})->throws(InvalidHealthCheck::class);
