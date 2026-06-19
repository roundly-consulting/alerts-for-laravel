<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts;

use Illuminate\Support\ServiceProvider;
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

        if ($this->app->runningInConsole()) {
            $this->commands([
                PerformHealthChecks::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/alerts.php' => config_path('alerts.php'),
            ], 'alerts-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'alerts-migrations');
        }
    }
}
