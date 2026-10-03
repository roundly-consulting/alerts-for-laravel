<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use RoundlyConsulting\Alerts\Actions\PruneHealthCheckRunsAction;
use RoundlyConsulting\Alerts\AlertsServiceProvider;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\HealthCheckRun;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/**
 * A typo in the host's alerts config fails loudly; it never silently degrades.
 *
 * The one that mattered: `ALERTS_HISTORY_RETENTION=five` used to be cast to 0 days, and
 * the daily prune then deleted the whole run history.
 */
function seedRunsDaysAgo(int ...$daysAgo): void
{
    $row = createHealthCheckWithNotifiable();

    foreach ($daysAgo as $days) {
        HealthCheckRun::factory()->create(['health_check_id' => $row->getKey(), 'ran_at' => now()->subDays($days)]);
    }
}

it('refuses a junk retention instead of pruning all history (strict config)', function (mixed $junk): void {
    config()->set('alerts.history.retention_days', $junk);
    seedRunsDaysAgo(1, 2);

    expect(fn () => app(PruneHealthCheckRunsAction::class)->execute())
        ->toThrow(InvalidConfigurationException::class, 'alerts.history.retention_days');

    expect(HealthCheckRun::count())->toBe(2);
})->with([
    'word' => 'five',
    'decimal' => '5.5',
    'blank' => '',
    'bool' => true,
]);

it('refuses a retention below one day (strict config)', function (mixed $days): void {
    config()->set('alerts.history.retention_days', $days);
    seedRunsDaysAgo(1);

    expect(fn () => Health::prune())->toThrow(InvalidConfigurationException::class, 'at least 1');
    expect(HealthCheckRun::count())->toBe(1);
})->with([0, '-3']);

it('reads a canonical integer retention string and defaults when absent (strict config)', function (): void {
    seedRunsDaysAgo(40, 10, 1);

    config()->set('alerts.history.retention_days', ' 5 ');
    expect(app(PruneHealthCheckRunsAction::class)->execute())->toBe(2);

    config()->set('alerts.history.retention_days', null);
    seedRunsDaysAgo(40);
    expect(app(PruneHealthCheckRunsAction::class)->execute())->toBe(1)
        ->and(HealthCheckRun::count())->toBe(1);
});

it('fails the prune command on a junk retention (strict config)', function (): void {
    config()->set('alerts.history.retention_days', 'five');
    seedRunsDaysAgo(1);

    expect(fn () => Artisan::call('alerts:prune-runs'))
        ->toThrow(InvalidConfigurationException::class, 'alerts.history.retention_days');
    expect(HealthCheckRun::count())->toBe(1);
});

it('refuses an unknown schedule frequency instead of running every minute (strict config)', function (mixed $frequency): void {
    config()->set('alerts.schedule.frequency', $frequency);

    expect(fn () => (new AlertsServiceProvider(app()))->scheduleCommand(new Schedule))
        ->toThrow(InvalidConfigurationException::class, 'alerts.schedule.frequency');
})->with([
    'typo' => 'everyMinuet',
    'not a frequency method' => 'withoutOverlapping',
    'sub-minute' => 'everyTenSeconds',
    'blank' => '',
    'array' => [['hourly']],
]);

it('schedules every minute when the frequency is absent (strict config)', function (): void {
    config()->set('alerts.schedule.frequency', null);

    $schedule = new Schedule;
    (new AlertsServiceProvider(app()))->scheduleCommand($schedule);

    expect($schedule->events()[0]->expression)->toBe('* * * * *');
});

it('refuses a blank or non-string health route uri or name (strict config)', function (string $key, mixed $value): void {
    config()->set($key, $value);

    expect(fn () => Health::routes())->toThrow(InvalidConfigurationException::class, $key);
})->with([
    'blank uri would serve the site root' => ['alerts.route.uri', ''],
    'array uri' => ['alerts.route.uri', ['health']],
    'blank name' => ['alerts.route.name', '  '],
    'int name' => ['alerts.route.name', 5],
]);

it('uses the default route uri and name when absent (strict config)', function (): void {
    config()->set('alerts.route.uri', null);
    config()->set('alerts.route.name', null);

    $route = Health::routes();

    expect($route->uri())->toBe('health')
        ->and($route->getName())->toBe('alerts.health');
});

it('renders the validated retention and frequency in about (strict config)', function (): void {
    config()->set('alerts.history.retention_days', '14');
    config()->set('alerts.schedule.frequency', 'everyFiveMinutes');

    expect('alerts')->toLeakNoSecrets(
        secrets: ['payments-oncall'],
        mustRender: ['ON (14 day retention)', 'ON (everyFiveMinutes)'],
    );
});

it('refuses to render about over a junk retention (strict config)', function (): void {
    config()->set('alerts.history.retention_days', 'five');

    expect(fn () => Artisan::call('about', ['--only' => 'alerts']))
        ->toThrow(InvalidConfigurationException::class, 'alerts.history.retention_days');
});
