<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Alerts\Commands\HealthCheckStatus;
use RoundlyConsulting\Alerts\Commands\PerformHealthChecks;

final class AlertsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/alerts.php', 'alerts');

        $this->app->singleton(Health::class);
        $this->app->alias(Health::class, 'health');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'alerts');

        $this->registerCommandSchedule();
        $this->registerCheckList();

        if ($this->app->runningInConsole()) {
            $this->commands([
                PerformHealthChecks::class,
                HealthCheckStatus::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/alerts.php' => config_path('alerts.php'),
            ], 'alerts-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'alerts-migrations');

            $this->publishes([
                __DIR__.'/../resources/lang' => $this->app->langPath('vendor/alerts'),
            ], 'alerts-translations');
        }
    }

    private function registerCommandSchedule(): void
    {
        if (config('alerts.schedule.enabled', true) !== true) {
            return;
        }

        $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $this->scheduleCommand($schedule));
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
