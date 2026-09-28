<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Actions\UnmuteAlertsAction;
use RoundlyConsulting\Alerts\AlertSilence;
use RoundlyConsulting\Alerts\Tests\Models\Team;

it('lifts global silences without touching scoped ones, and vice versa', function (): void {
    $team = Team::create();

    AlertSilence::factory()->create(['key' => 'db']);
    AlertSilence::factory()->create(['key' => 'db', 'notifiable_type' => $team->getMorphClass(), 'notifiable_id' => $team->getKey()]);
    AlertSilence::factory()->create(['key' => 'cache']);

    $action = app(UnmuteAlertsAction::class);

    expect($action->execute('db'))->toBe(1)
        ->and(AlertSilence::query()->where('key', 'db')->count())->toBe(1)
        ->and($action->execute('db', $team))->toBe(1)
        ->and(AlertSilence::query()->pluck('key')->all())->toBe(['cache']);
});
