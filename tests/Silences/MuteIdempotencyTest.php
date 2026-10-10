<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Alerts\AlertSilence;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\Support\Identity;
use RoundlyConsulting\Alerts\Tests\Models\Team;

/**
 * `mute()` is an upsert on the silence's identity — its key and its scope — so a mute button
 * clicked twice, or a deploy script run on every deploy, keeps one row instead of stacking one
 * per call.
 */
beforeEach(function (): void {
    Carbon::setTestNow('2026-10-10 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('updates the one silence a second mute of the same key writes', function (): void {
    $first = Health::silences()->mute('db', until: now()->addHour(), reason: 'deploy');
    $second = Health::silences()->mute('db', until: now()->addHours(3), reason: 'migration');

    $row = AlertSilence::query()->sole();

    expect($second->getKey())->toBe($first->getKey())
        ->and($row->getKey())->toBe($first->getKey())
        ->and($row->ends_at?->toDateTimeString())->toBe('2026-10-10 15:00:00')
        ->and($row->reason)->toBe('migration')
        ->and($second->ends_at?->toDateTimeString())->toBe('2026-10-10 15:00:00');
});

it('can shorten a silence as well as extend it', function (): void {
    Health::silences()->mute('db');
    Health::silences()->mute('db', until: now()->addMinutes(10));

    expect(AlertSilence::query()->sole()->ends_at?->toDateTimeString())->toBe('2026-10-10 12:10:00');

    Health::silences()->mute('db');

    expect(AlertSilence::query()->sole()->ends_at)->toBeNull();
});

it('keeps global and scoped silences of one key apart', function (): void {
    $team = Team::create();
    $other = Team::create();

    foreach ([1, 2] as $round) {
        Health::silences()->mute('db');
        Health::silences()->mute('db', for: $team);
        Health::silences()->mute('db', for: $other);
        Health::silences()->mute('cache', for: $team);
    }

    expect(AlertSilence::query()->count())->toBe(4)
        ->and(AlertSilence::query()->whereNull('notifiable_id')->count())->toBe(1)
        ->and(AlertSilence::query()->where('key', 'db')->count())->toBe(3);
});

it('stores the identity of the silence it writes', function (): void {
    $team = Team::create();

    $global = Health::silences()->mute('db');
    $scoped = Health::silences()->mute('db', for: $team);

    expect($global->fresh()?->identity_hash)->toBe(Identity::silence('db', null, null))
        ->and($scoped->fresh()?->identity_hash)->toBe(Identity::silence('db', $team->getMorphClass(), $team->getKey()));
});

it('updates a row that already holds the identity instead of failing', function (): void {
    $held = AlertSilence::factory()->create([
        'key' => 'db',
        'ends_at' => now()->addMinute(),
        'identity_hash' => Identity::silence('db', null, null),
    ]);

    $silence = Health::silences()->mute('db', until: now()->addDay(), reason: 'longer');

    expect($silence->getKey())->toBe($held->getKey())
        ->and(AlertSilence::query()->sole()->reason)->toBe('longer')
        ->and(AlertSilence::query()->sole()->ends_at?->toDateTimeString())->toBe('2026-10-11 12:00:00');
});

it('leaves a silence written straight through the model alone', function (): void {
    AlertSilence::factory()->create(['key' => 'db']);

    Health::silences()->mute('db');
    Health::silences()->mute('db');

    expect(AlertSilence::query()->count())->toBe(2)
        ->and(AlertSilence::query()->whereNull('identity_hash')->count())->toBe(1);
});
