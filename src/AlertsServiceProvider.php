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
use RoundlyConsulting\Alerts\Support\AlertSilenceModel;
use RoundlyConsulting\Alerts\Support\HealthCheckModel;
use RoundlyConsulting\Alerts\Support\HealthCheckRunModel;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;

final class AlertsServiceProvider extends PackageServiceProvider
{
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

        $this->app->singleton(Health::class);
        $this->app->alias(Health::class, 'health');
    }

    public function boot(): void
    {
        parent::boot();

        $this->registerCommandSchedule();
        $this->registerCheckList();
    }

    public function scheduleCommand(Schedule $schedule): void
    {
        $frequency = (string) config('alerts.schedule.frequency', 'everyMinute');

        $event = $schedule->command('alerts:perform-health-checks')->withoutOverlapping();

        if (method_exists($event, $frequency)) {
            $event->{$frequency}();
        } else {
            $event->everyMinute();
        }

        if (config('alerts.history.enabled', true) === true) {
            $schedule->command('alerts:prune-runs')->daily();
        }
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
            'Silences' => config('alerts.silence', true) === true ? 'ON' : 'OFF',
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
        if (config('alerts.schedule.enabled', true) !== true) {
            return 'OFF';
        }

        return sprintf('ON (%s)', (string) config('alerts.schedule.frequency', 'everyMinute'));
    }

    private function history(): string
    {
        if (config('alerts.history.enabled', true) !== true) {
            return 'OFF';
        }

        return sprintf('ON (%d day retention)', (int) config('alerts.history.retention_days', 30));
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
        if (config('alerts.schedule.enabled', true) !== true) {
            return;
        }

        $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $this->scheduleCommand($schedule));
    }

    private function registerCheckList(): void
    {
        /** @var array<int, string|object> $checks */
        $checks = config('alerts.checks', []);

        if ($checks !== []) {
            $this->app->make(Health::class)->checks($checks);
        }
    }
}
