<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts;

use Illuminate\Console\Scheduling\Schedule;
use RoundlyConsulting\Alerts\Commands\HealthCheckStatus;
use RoundlyConsulting\Alerts\Commands\ListChecks;
use RoundlyConsulting\Alerts\Commands\PerformHealthChecks;
use RoundlyConsulting\Alerts\Commands\PruneRuns;
use RoundlyConsulting\Alerts\Commands\RunCheck;
use RoundlyConsulting\Alerts\Support\AlertModel;
use RoundlyConsulting\Alerts\Support\AlertsConfig;
use RoundlyConsulting\Alerts\Support\AlertSilenceModel;
use RoundlyConsulting\Alerts\Support\HealthCheckModel;
use RoundlyConsulting\Alerts\Support\HealthCheckRunModel;
use RoundlyConsulting\PackageToolkit\Concerns\RegistersBlueprintMacros;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;
use RoundlyConsulting\PackageToolkit\Support\Config;

final class AlertsServiceProvider extends PackageServiceProvider
{
    use RegistersBlueprintMacros;

    public function configurePackage(Package $package): void
    {
        $package
            ->name('alerts')
            ->hasConfigFile()
            ->hasMigrations()
            ->hasTranslations()
            ->hasCommands([
                PerformHealthChecks::class,
                HealthCheckStatus::class,
                ListChecks::class,
                RunCheck::class,
                PruneRuns::class,
            ])
            ->contributesToAbout(fn (): array => $this->aboutSection());
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(HealthManager::class);
    }

    public function boot(): void
    {
        parent::boot();

        // The migrations' key-type-aware morph columns are macros, so they must
        // exist before a host runs `php artisan migrate`.
        $this->registerBlueprintMacros();

        $this->registerCommandSchedule();
        $this->registerCheckList();
    }

    public function scheduleCommand(Schedule $schedule): void
    {
        $frequency = AlertsConfig::scheduleFrequency();

        $schedule->command('alerts:perform-health-checks')->withoutOverlapping()->{$frequency}();
    }

    /**
     * Pruning follows `history.enabled` alone. `schedule.enabled = false` is how a host
     * takes the perform command over; it must not silently stop the run history from
     * being pruned too.
     */
    public function schedulePrune(Schedule $schedule): void
    {
        $schedule->command('alerts:prune-runs')->daily();
    }

    /**
     * A monitoring package's config names the host's own topology: a registered check
     * names what it watches (a connection, a disk, an internal URL) and an escalation
     * policy names the groups it pages. Neither ever renders — checks report as a
     * count and the policy as its depth. The models render by base name; everything
     * else is a switch or a bound.
     *
     * @return array<string, string>
     */
    private function aboutSection(): array
    {
        return [
            'Health check model' => class_basename(HealthCheckModel::class()),
            'Alert model' => class_basename(AlertModel::class()),
            'Silence model' => class_basename(AlertSilenceModel::class()),
            'Run model' => class_basename(HealthCheckRunModel::class()),
            'Registered checks' => $this->registeredChecks(),
            'Scheduling' => $this->scheduling(),
            'History' => $this->history(),
            'Silences' => Config::boolean('alerts.silence', true) ? 'ON' : 'OFF',
            'Default escalation' => $this->defaultEscalation(),
            'Health endpoint' => $this->healthEndpoint(),
        ];
    }

    /**
     * The endpoint is unauthenticated by design, so hosts routinely move it to an
     * obscure path — presence only, never the URI.
     */
    private function healthEndpoint(): string
    {
        return config('alerts.route.uri', 'health') === 'health' ? 'DEFAULT' : 'SET';
    }

    private function registeredChecks(): string
    {
        $checks = config('alerts.checks', []);

        return is_array($checks) && $checks !== []
            ? sprintf('%d registered', count($checks))
            : 'NONE';
    }

    private function scheduling(): string
    {
        if (! Config::boolean('alerts.schedule.enabled', true)) {
            return 'OFF';
        }

        return sprintf('ON (%s)', AlertsConfig::scheduleFrequency());
    }

    private function history(): string
    {
        if (! Config::boolean('alerts.history.enabled', true)) {
            return 'OFF';
        }

        return sprintf('ON (%d day retention)', AlertsConfig::retentionDays());
    }

    private function defaultEscalation(): string
    {
        $policy = config('alerts.escalation', []);

        return is_array($policy) && $policy !== []
            ? sprintf('%d level(s)', count($policy))
            : 'NONE';
    }

    private function registerCommandSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (Config::boolean('alerts.schedule.enabled', true)) {
                $this->scheduleCommand($schedule);
            }

            if (Config::boolean('alerts.history.enabled', true)) {
                $this->schedulePrune($schedule);
            }
        });
    }

    private function registerCheckList(): void
    {
        /** @var array<int, string|object> $checks */
        $checks = config('alerts.checks', []);

        if ($checks !== []) {
            $this->app->make(HealthManager::class)->checks($checks);
        }
    }
}
