<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Alerts\AlertsServiceProvider;
use RoundlyConsulting\PackageToolkit\Enums\DatabaseDriver;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * Alerts ships four migrations wired by two real foreign keys — `alerts.health_check_id`
 * and `health_check_runs.health_check_id`, both onto `health_checks`. Migrations are
 * publish-only and publishing preserves the source order, so that order has to be runnable
 * end to end from an empty database.
 *
 * This file replaces ~110 lines of hand-rolled machinery: a temp-directory publisher, a
 * second SQLite connection, and a bespoke regex FK parser. The parser was genuinely good
 * work — it understood both of Laravel's FK forms and guarded its own parse — but it is
 * exactly the code the testing package exists to own once, and its SQLite-based `migrate`
 * assertions could never fail on a broken order: SQLite creates a table pointing at a
 * missing parent and only complains at insert time, which is the mechanism behind five
 * packages shipping uninstallable migration orders under green suites.
 */
$migrations = __DIR__.'/../../database/migrations';

/**
 * M — the structural pin, and the only one of these that catches a broken order on SQLite.
 *
 * `foreignKeys: 2` is what stops it passing over an empty parse: the count is pinned, so a
 * parser that silently understood nothing fails instead of reporting success over zero
 * edges. Alerts declares its two keys in BOTH of Laravel's forms — the long-hand
 * `->references('id')->on('health_checks')` (the fleet's named case for that form, alerts
 * #34) and `->constrained('health_checks')` — so this also pins that both stay parseable.
 */
it('creates every foreign key target before the table that references it', function () use ($migrations): void {
    expect($migrations)->toHaveRunnableMigrationOrder(foreignKeys: 2);
});

/**
 * P — the publish-only guards. The fleet publishes migrations timestamped rather than
 * auto-loading them; doing both runs both copies and dies on a duplicate table (bug #5, on
 * three packages). `4` pins the file count so neither check can pass over an empty or
 * relocated directory.
 */
it('never auto-loads its migrations — the host publishes them', function (): void {
    expect(AlertsServiceProvider::class)->toNotAutoLoadMigrations();
});

it('publishes its migrations timestamp-injected into the host', function (): void {
    expect(AlertsServiceProvider::class)->toPublishMigrationsTimestamped('alerts-migrations', 4);
});

/**
 * R — the real-engine proof. The old version of this file migrated the published files
 * into a second SQLite database, which is not a proof: SQLite accepts a dangling foreign
 * key at DDL time. Postgres rejects it, so this is the assertion that actually watches the
 * shipped order install.
 */
it('applies its migrations on postgres', function () use ($migrations): void {
    expect($migrations)->toApplyOnConnection('pgsql', migrations: 4);
})->skip(fn (): bool => ! test()->connectionAvailable('pgsql'), 'no postgres connection available');

/**
 * R's negative control — the half that makes the one above mean something. A green FK test
 * proves nothing until you have watched the engine *reject* the broken order (forms #28).
 *
 * This is adoptable here precisely because alerts has FK edges: reverse the four files and
 * `create_alerts_table` runs before `create_health_checks_table`, so Postgres must refuse
 * with `relation "health_checks" does not exist`. Verified non-vacuous by pointing it at
 * the sqlite connection, where it does not quietly pass but **fails loudly** — "the engine
 * ACCEPTED a deliberately broken migration order" — which is why it is gated on a real
 * connection rather than the default one.
 *
 * (The 0-FK rows in this wave cannot adopt this: with nothing to violate, Postgres accepts
 * the reversed list and the assertion fails by design. Alerts is the row in this batch that
 * has something to refuse.)
 */
it('rejects a child-before-parent order on postgres', function () use ($migrations): void {
    expect($migrations)->toRejectBrokenOrderOnConnection(
        fn (array $files): array => array_reverse($files),
        'pgsql',
    );
})->skip(fn (): bool => ! test()->connectionAvailable('pgsql'), 'no postgres connection available');

/**
 * The driver-truth pin (Wave 2's lesson). It compares the env-DECLARED driver against what
 * the connection itself answers, so a "pgsql" leg that quietly stayed on SQLite — a
 * decapitated `defineEnvironment()`, a missing `TESTING_DB_DRIVER` — goes red here rather
 * than passing as a postgres run. It fires automatically, unlike reading a skip count by
 * hand.
 */
it('runs on the driver the environment declares', function (): void {
    expect(DatabaseDriver::current())->toBe(DatabaseDriver::from(DriverMatrix::driver()));
});

/**
 * The `json` tags/meta columns and the morph columns are what the drivers render
 * differently. Pinning a round-trip on whatever engine the leg configured proves the
 * columns are usable rather than merely creatable.
 */
it('round-trips the alert columns on the configured engine', function (): void {
    $check = createHealthCheckWithNotifiable();

    $check->update(['tags' => ['db', 'critical'], 'meta' => ['region' => 'eu', 'tier' => 2]]);

    $alert = createAlertForHealthCheck($check);

    $fresh = $check->fresh();

    expect($fresh->tags)->toBe(['db', 'critical'])
        ->and($fresh->meta)->toBe(['region' => 'eu', 'tier' => 2])
        ->and($alert->fresh()->health_check_id)->toBe($check->getKey())
        ->and(DB::connection()->getDriverName())->toBe(DriverMatrix::driver());
});
