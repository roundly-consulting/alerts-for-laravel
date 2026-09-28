<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Actions\UnscheduleHealthCheckAction;
use RoundlyConsulting\Alerts\Exceptions\InvalidHealthCheck;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;

it('soft-deletes only the notifiable\'s schedules for a key', function (): void {
    $team = Team::create();
    $mine = createHealthCheckWithNotifiable($team);
    $theirs = createHealthCheckWithNotifiable(Team::create());

    expect(app(UnscheduleHealthCheckAction::class)->execute($team, 'example_health_check'))->toBe(1)
        ->and($mine->fresh()?->trashed())->toBeTrue()
        ->and($theirs->fresh()?->trashed())->toBeFalse();
});

it('refuses a row that belongs to another notifiable', function (): void {
    $theirs = createHealthCheckWithNotifiable(Team::create());

    app(UnscheduleHealthCheckAction::class)->execute(Team::create(), $theirs);
})->throws(InvalidHealthCheck::class);

it('removes nothing for an unknown key', function (): void {
    createHealthCheckWithNotifiable($team = Team::create());

    expect(app(UnscheduleHealthCheckAction::class)->execute($team, 'unknown'))->toBe(0)
        ->and(HealthCheck::count())->toBe(1);
});
