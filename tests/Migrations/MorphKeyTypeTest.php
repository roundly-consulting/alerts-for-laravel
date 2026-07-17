<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * The three outbound `notifiable` morph columns follow `alerts.key_type` (default `bigint`)
 * through the toolkit's `morphKey` macro. Two things must hold and are proven here:
 *
 *  - the default (`bigint`) emitted schema is BYTE-IDENTICAL to the pre-macro `morphs()`
 *    output — `morphKey($n, BigInt)` *is* `morphs($n)` — so a default host sees zero change;
 *  - a `uuid` / `ulid` host actually gets uuid / char morph id columns, checked on the only
 *    engine (Postgres) whose catalog can tell the three key types apart.
 */
function runAlertsMigrations(): void
{
    foreach ([
        '2024_01_01_000001_create_health_checks_table',
        '2024_01_01_000002_create_alerts_table',
        '2024_01_01_000003_create_alert_silences_table',
        '2024_01_01_000004_create_health_check_runs_table',
    ] as $migration) {
        (require __DIR__.'/../../database/migrations/'.$migration.'.php')->up();
    }
}

/**
 * Drop only this package's tables, FK-referencing tables first, so a re-migration under a
 * different key type does not break the harness reset or trip a foreign-key constraint.
 */
function dropAlertsTables(): void
{
    foreach (['alerts', 'health_check_runs', 'alert_silences', 'health_checks'] as $table) {
        Schema::dropIfExists($table);
    }
}

function emittedAlertsTable(string $table): string
{
    /** @var list<object{sql: string|null}> $rows */
    $rows = DB::select('select sql from sqlite_master where type = ? and name = ?', ['table', $table]);

    return (string) ($rows[0]->sql ?? '');
}

function pgsqlAlertsColumnType(string $table, string $column): string
{
    /** @var list<object{data_type: string, character_maximum_length: int|null}> $rows */
    $rows = DB::select(
        'select data_type, character_maximum_length from information_schema.columns where table_name = ? and column_name = ?',
        [$table, $column],
    );

    $row = $rows[0] ?? null;

    if ($row === null) {
        return 'MISSING';
    }

    return $row->character_maximum_length === null
        ? $row->data_type
        : $row->data_type.'('.$row->character_maximum_length.')';
}

$sqliteOnly = fn (): bool => DriverMatrix::driver() !== 'sqlite';
$pgsqlOnly = fn (): bool => DriverMatrix::driver() !== 'pgsql';

it('emits the frozen bigint morph schema byte-for-byte', function (): void {
    // The harness has already migrated on the default (bigint) config. This is the shipped
    // schema — the sweep's core safety property is that it must never drift.
    expect(emittedAlertsTable('health_checks'))->toBe(
        'CREATE TABLE "health_checks" ("id" integer primary key autoincrement not null, '
        .'"notifiable_type" varchar not null, "notifiable_id" integer not null, '
        .'"health_check" varchar not null, "frequency" varchar not null, '
        .'"max_attempts" integer not null default \'1\', "decay_minutes" integer not null default \'1\', '
        .'"consecutive_failures" integer not null default \'0\', "consecutive_successes" integer not null default \'0\', '
        .'"tags" text, "meta" text, "created_at" datetime, "updated_at" datetime, "deleted_at" datetime)'
    );

    expect(emittedAlertsTable('alerts'))->toBe(
        'CREATE TABLE "alerts" ("id" integer primary key autoincrement not null, '
        .'"notifiable_type" varchar not null, "notifiable_id" integer not null, '
        .'"health_check_id" integer not null, "status" varchar not null default \'failed\', '
        .'"escalation_level" integer not null default \'0\', "message" varchar, "meta" text, '
        .'"triggered_at" datetime not null, "recovered_at" datetime, '
        .'"created_at" datetime, "updated_at" datetime, "deleted_at" datetime, '
        .'foreign key("health_check_id") references "health_checks"("id") on delete cascade)'
    );

    expect(emittedAlertsTable('alert_silences'))->toBe(
        'CREATE TABLE "alert_silences" ("id" integer primary key autoincrement not null, '
        .'"key" varchar not null, "notifiable_type" varchar, "notifiable_id" integer, '
        .'"reason" varchar, "starts_at" datetime, "ends_at" datetime, '
        .'"created_at" datetime, "updated_at" datetime)'
    );
})->skip($sqliteOnly, 'sqlite_master is the sqlite catalog');

it('renders each configured key type as a distinct real morph column type', function (string $keyType, string $expected): void {
    config()->set('alerts.key_type', $keyType);

    dropAlertsTables();
    runAlertsMigrations();

    // Every polymorphic notifiable id column follows the configured type.
    expect(pgsqlAlertsColumnType('health_checks', 'notifiable_id'))->toBe($expected)
        ->and(pgsqlAlertsColumnType('alerts', 'notifiable_id'))->toBe($expected)
        ->and(pgsqlAlertsColumnType('alert_silences', 'notifiable_id'))->toBe($expected)
        // The morph *type* column names a class — a string on every key type.
        ->and(pgsqlAlertsColumnType('health_checks', 'notifiable_type'))->toBe('character varying(255)');
})->with([
    'bigint' => ['bigint', 'bigint'],
    'uuid' => ['uuid', 'uuid'],
    'ulid' => ['ulid', 'character(26)'],
])->skip($pgsqlOnly, 'needs the postgres catalog to tell the key types apart');

it('falls back to the bigint morph schema for an unrecognized key type', function (): void {
    config()->set('alerts.key_type', 'nonsense');

    dropAlertsTables();
    runAlertsMigrations();

    // A typo in a host's config must never leave the package unable to migrate.
    expect(Schema::hasColumn('health_checks', 'notifiable_id'))->toBeTrue()
        ->and(DriverMatrix::driver() === 'pgsql' ? pgsqlAlertsColumnType('health_checks', 'notifiable_id') : 'bigint')
        ->toBe('bigint');
});
