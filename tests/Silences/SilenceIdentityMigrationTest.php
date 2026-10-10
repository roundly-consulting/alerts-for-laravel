<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Alerts\Support\Identity;
use RoundlyConsulting\Alerts\Tests\Models\Team;

/**
 * `2024_01_01_000006_add_identity_hash_to_alert_silences_table` collapses the immediate
 * silences a host already stacked (one per mute click) into one per identity before the
 * unique index goes on, without changing what is muted: the newest row survives, open-ended
 * if any of them was, else until the latest end, with the newest reason.
 *
 * It runs on whatever engine the leg configured — the dedupe and the index must hold on
 * MySQL and PostgreSQL, not just SQLite.
 */
function silenceTable()
{
    return Schema::getConnection()->table('alert_silences');
}

function silenceRow(array $attributes): int
{
    return (int) silenceTable()->insertGetId([
        'notifiable_type' => null,
        'notifiable_id' => null,
        'reason' => null,
        'starts_at' => null,
        'ends_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
        ...$attributes,
    ]);
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-10 12:00:00');

    // Back to the 1.1.0 shape: no identity column, no index.
    Schema::table('alert_silences', function (Blueprint $table): void {
        $table->dropUnique('alert_silences_identity_hash_unique');
    });
    Schema::table('alert_silences', function (Blueprint $table): void {
        $table->dropColumn('identity_hash');
    });

    $this->migration = require __DIR__.'/../../database/migrations/2024_01_01_000006_add_identity_hash_to_alert_silences_table.php';
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('collapses stacked immediate silences into one per identity, keeping what is muted', function (): void {
    $team = Team::create();

    silenceRow(['key' => 'db', 'reason' => 'first', 'ends_at' => null]);
    silenceRow(['key' => 'db', 'reason' => 'second', 'ends_at' => now()->addHour()]);
    $newest = silenceRow(['key' => 'db', 'reason' => null, 'ends_at' => now()->subDay()]);

    silenceRow(['key' => 'cache', 'ends_at' => now()->addMinutes(5)]);
    silenceRow(['key' => 'cache', 'ends_at' => now()->addHours(2)]);
    $newestCache = silenceRow(['key' => 'cache', 'ends_at' => now()->subHour()]);

    $scoped = silenceRow(['key' => 'db', 'notifiable_type' => $team->getMorphClass(), 'notifiable_id' => $team->getKey(), 'reason' => 'scoped']);
    $window = silenceRow(['key' => 'db', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);

    $this->migration->up();

    $db = silenceTable()->where('key', 'db')->whereNull('notifiable_id')->whereNull('starts_at')->get();
    $cache = silenceTable()->where('key', 'cache')->get();

    expect($db)->toHaveCount(1)
        ->and((int) $db[0]->id)->toBe($newest)
        ->and($db[0]->ends_at)->toBeNull()
        ->and($db[0]->reason)->toBe('second')
        ->and($db[0]->identity_hash)->toBe(Identity::silence('db', null, null))
        ->and($cache)->toHaveCount(1)
        ->and((int) $cache[0]->id)->toBe($newestCache)
        ->and(Carbon::parse($cache[0]->ends_at)->toDateTimeString())->toBe('2026-10-10 14:00:00')
        ->and($cache[0]->identity_hash)->toBe(Identity::silence('cache', null, null))
        ->and(silenceTable()->where('id', $scoped)->value('identity_hash'))
        ->toBe(Identity::silence('db', $team->getMorphClass(), $team->getKey()))
        ->and(silenceTable()->where('id', $window)->value('identity_hash'))->toBeNull()
        ->and(silenceTable()->count())->toBe(4);
});

it('adds the unique identity index', function (): void {
    silenceRow(['key' => 'db']);

    $this->migration->up();

    expect(Schema::hasIndex('alert_silences', 'alert_silences_identity_hash_unique', 'unique'))->toBeTrue();

    expect(fn () => silenceRow(['key' => 'db', 'identity_hash' => Identity::silence('db', null, null)]))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('migrates an empty table', function (): void {
    $this->migration->up();

    expect(Schema::hasColumn('alert_silences', 'identity_hash'))->toBeTrue()
        ->and(silenceTable()->count())->toBe(0);
});
