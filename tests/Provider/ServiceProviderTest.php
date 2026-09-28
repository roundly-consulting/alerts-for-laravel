<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Alerts\AlertsServiceProvider;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\HealthManager;

it('merges the package config', function (): void {
    expect(config('alerts.health-check'))->toBe(HealthCheck::class);
});

it('binds the health manager as a singleton under its class name only', function (): void {
    expect(app(HealthManager::class))->toBeInstanceOf(HealthManager::class)
        ->and(app(HealthManager::class))->toBe(app(HealthManager::class))
        ->and(app()->bound('health'))->toBeFalse();
});

it('loads the package translations', function (): void {
    expect(trans('alerts::checks.database_reachable', ['connection' => 'pgsql']))
        ->toBe('Database connection [pgsql] is reachable.');
});

it('publishes the config file', function (): void {
    $published = ServiceProvider::pathsToPublish(AlertsServiceProvider::class, 'alerts-config');

    expect(array_values($published))->toBe([config_path('alerts.php')]);
});

it('publishes the translations', function (): void {
    $published = ServiceProvider::pathsToPublish(AlertsServiceProvider::class, 'alerts-translations');

    expect(array_values($published))->toBe([app()->langPath('vendor/alerts')]);
});

/**
 * Migrations are PUBLISH-ONLY (fleet policy): the package must never add its own
 * migration directory to the migrator, so a host's `php artisan migrate` runs exactly
 * the files it published — never a second, differently-named copy of the same
 * `Schema::create()`.
 */
it('never auto-loads its migrations', function (): void {
    expect(app('migrator')->paths())
        ->not->toContain(realpath(__DIR__.'/../../database/migrations'));
});

it('publishes every migration timestamped, in dependency order', function (): void {
    $published = ServiceProvider::pathsToPublish(AlertsServiceProvider::class, 'alerts-migrations');

    $names = array_map(
        static fn (string $target): string => basename($target),
        array_values($published),
    );

    expect($names)->toHaveCount(4);

    foreach ($names as $name) {
        expect($name)->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_create_[a-z_]+_table\.php$/');
    }

    // Publishing preserves the source order (one second per file), so a host creates
    // `health_checks` before the two tables that carry a foreign key onto it.
    $sorted = $names;
    sort($sorted);

    expect($sorted)->toBe($names)
        ->and($names[0])->toContain('create_health_checks_table')
        ->and($names[1])->toContain('create_alerts_table')
        ->and($names[3])->toContain('create_health_check_runs_table');
});

it('creates the schema when the published migrations are run', function (): void {
    expect(Schema::hasTable('health_checks'))->toBeTrue()
        ->and(Schema::hasTable('alerts'))->toBeTrue()
        ->and(Schema::hasTable('alert_silences'))->toBeTrue()
        ->and(Schema::hasTable('health_check_runs'))->toBeTrue();
});

it('registers the package commands', function (): void {
    expect(array_keys(Artisan::all()))
        ->toContain('alerts:perform-health-checks')
        ->toContain('alerts:status')
        ->toContain('alerts:list')
        ->toContain('alerts:check')
        ->toContain('alerts:prune-runs');
});
