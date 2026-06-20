<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Alerts\AlertSilence;
use RoundlyConsulting\Alerts\Tests\Models\Team;

it('matches active silences within their window', function () {
    Carbon::setTestNow('2026-06-20 12:00:00');

    AlertSilence::factory()->create([
        'key' => 'db',
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
    ]);

    expect(AlertSilence::query()->matching(['db'])->active(now())->exists())->toBeTrue();

    Carbon::setTestNow();
});

it('excludes silences outside their window', function () {
    Carbon::setTestNow('2026-06-20 12:00:00');

    AlertSilence::factory()->create([
        'key' => 'db',
        'starts_at' => now()->addHour(),
    ]);

    expect(AlertSilence::query()->matching(['db'])->active(now())->exists())->toBeFalse();

    Carbon::setTestNow();
});

it('matches a global silence regardless of notifiable scope', function () {
    $team = Team::create();

    AlertSilence::factory()->create(['key' => '*']);

    expect(AlertSilence::query()->matching(['*'], $team)->active(now())->exists())->toBeTrue();
});

it('scopes a notifiable silence to that notifiable only', function () {
    $team = Team::create();
    $other = Team::create();

    AlertSilence::factory()->create([
        'key' => 'db',
        'notifiable_type' => $team->getMorphClass(),
        'notifiable_id' => $team->getKey(),
    ]);

    expect(AlertSilence::query()->matching(['db'], $team)->active(now())->exists())->toBeTrue()
        ->and(AlertSilence::query()->matching(['db'], $other)->active(now())->exists())->toBeFalse();
});
