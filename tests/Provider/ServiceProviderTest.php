<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Alerts\AlertsServiceProvider;
use RoundlyConsulting\Alerts\Checks\DatabaseCheck;
use RoundlyConsulting\Alerts\Health;
use RoundlyConsulting\Alerts\HealthCheck;

it('merges the package config', function (): void {
    expect(config('alerts.health-check'))->toBe(HealthCheck::class);
});

it('binds the health manager as a singleton behind both keys', function (): void {
    expect(app(Health::class))->toBeInstanceOf(Health::class)
        ->and(app('health'))->toBe(app(Health::class));
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

/**
 * A monitoring package's config names the host's own topology: a registered check
 * names what it watches (a connection, a disk, an internal URL) and an escalation
 * policy names the groups it pages. Neither may ever render.
 */
it('contributes a secret-safe section to the about command', function (): void {
    config()->set('alerts.checks', [DatabaseCheck::class]);
    config()->set('alerts.escalation', [1 => 'payments-oncall', 3 => 'cto']);
    config()->set('alerts.route.uri', 'internal/ops/health-x9f2');

    Artisan::call('about', ['--only' => 'alerts']);

    $output = Artisan::output();

    // Guard the guard: an empty capture would make every negative below vacuous.
    expect($output)->toContain('Health check model')
        ->toContain('1 registered')
        ->toContain('2 level(s)');

    // The on-call vocabulary, the class of check being run, and the (deliberately
    // obscure) endpoint path are the host's — presence and counts only.
    expect($output)->not->toContain('payments-oncall')
        ->not->toContain('cto')
        ->not->toContain('DatabaseCheck')
        ->not->toContain('internal/ops/health-x9f2');
});

it('reports the configured models and switches in the about section', function (): void {
    config()->set('alerts.silence', false);
    config()->set('alerts.history.enabled', false);
    config()->set('alerts.schedule.enabled', false);

    Artisan::call('about', ['--only' => 'alerts']);

    $output = Artisan::output();

    expect($output)->toContain('HealthCheck')
        ->toContain('AlertSilence')
        ->toContain('HealthCheckRun')
        ->toContain('OFF')
        ->toContain('NONE');
});
