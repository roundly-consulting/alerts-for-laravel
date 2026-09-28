<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Alerts\Actions\MuteAlertsAction;
use RoundlyConsulting\Alerts\AlertSilence;
use RoundlyConsulting\Alerts\Tests\Models\Team;

it('stores a silence scoped to a notifiable until a moment', function (): void {
    Carbon::setTestNow('2026-09-28 10:00:00');
    $team = Team::create();

    $silence = app(MuteAlertsAction::class)->execute('db', now()->addHour(), $team, 'deploy');

    expect($silence->exists)->toBeTrue()
        ->and($silence->key)->toBe('db')
        ->and($silence->notifiable?->is($team))->toBeTrue()
        ->and($silence->reason)->toBe('deploy')
        ->and($silence->starts_at)->toBeNull()
        ->and($silence->ends_at?->toDateTimeString())->toBe('2026-09-28 11:00:00');

    Carbon::setTestNow();
});

it('stores an open-ended global silence', function (): void {
    $silence = app(MuteAlertsAction::class)->execute(AlertSilence::GLOBAL_KEY);

    expect($silence->notifiable_id)->toBeNull()
        ->and($silence->ends_at)->toBeNull();
});
