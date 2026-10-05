<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Actions\UnscheduleHealthCheckAction;
use RoundlyConsulting\Alerts\Alert;
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

it('closes the open alert of every row it unmonitors', function (): void {
    $team = Team::create();
    $row = createHealthCheckWithNotifiable($team);
    $theirs = createHealthCheckWithNotifiable(Team::create());

    $open = Alert::factory()->create([
        'health_check_id' => $row->getKey(),
        'notifiable_type' => $row->notifiable_type,
        'notifiable_id' => $row->notifiable_id,
        'open_slot' => Alert::OPEN_SLOT,
    ]);
    $untouched = Alert::factory()->create([
        'health_check_id' => $theirs->getKey(),
        'notifiable_type' => $theirs->notifiable_type,
        'notifiable_id' => $theirs->notifiable_id,
        'open_slot' => Alert::OPEN_SLOT,
    ]);

    expect(app(UnscheduleHealthCheckAction::class)->execute($team, 'example_health_check'))->toBe(1);

    $open->refresh();

    expect($open->recovered_at)->not->toBeNull()
        ->and($open->open_slot)->toBeNull()
        ->and($untouched->fresh()?->recovered_at)->toBeNull()
        ->and($team->alerts()->open()->count())->toBe(0);
});

it('closes the open alert of a row unmonitored by itself', function (): void {
    $team = Team::create();
    $row = createHealthCheckWithNotifiable($team);
    $open = Alert::factory()->create([
        'health_check_id' => $row->getKey(),
        'notifiable_type' => $row->notifiable_type,
        'notifiable_id' => $row->notifiable_id,
        'open_slot' => Alert::OPEN_SLOT,
    ]);

    expect(app(UnscheduleHealthCheckAction::class)->execute($team, $row))->toBe(1)
        ->and($open->fresh()?->recovered_at)->not->toBeNull()
        ->and($open->fresh()?->open_slot)->toBeNull();
});
