<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Jobs\HealthCheckJob;

return [

    /*
    |--------------------------------------------------------------------------
    | Health Check Model
    |--------------------------------------------------------------------------
    |
    | The Eloquent model used to store scheduled health checks. Override this
    | with your own model (extending the package model) to customise behaviour.
    |
    */

    'health-check' => HealthCheck::class,

    /*
    |--------------------------------------------------------------------------
    | Registered Checks
    |--------------------------------------------------------------------------
    |
    | Check classes (or instances) registered globally on boot. Add the built-in
    | checks or your own here, or register them at runtime via Health::check().
    |
    */

    'checks' => [
        // RoundlyConsulting\Alerts\Checks\DatabaseCheck::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Alert Model
    |--------------------------------------------------------------------------
    |
    | The Eloquent model used to record alerts when a health check fails and
    | the recovery timestamp once it passes again.
    |
    */

    'alert' => Alert::class,

    /*
    |--------------------------------------------------------------------------
    | Health Check Job
    |--------------------------------------------------------------------------
    |
    | The queued job dispatched for each due health check. Swap it for your own
    | job to change how checks are executed or notifications are delivered.
    |
    */

    'job' => HealthCheckJob::class,

    /*
    |--------------------------------------------------------------------------
    | Scheduling
    |--------------------------------------------------------------------------
    |
    | When enabled, the package auto-registers the `alerts:perform-health-checks`
    | command on Laravel's scheduler at the given frequency, so install-to-working
    | is a single config line. Disable it to wire the command yourself.
    |
    */

    'schedule' => [
        'enabled' => env('ALERTS_SCHEDULE', true),
        'frequency' => env('ALERTS_SCHEDULE_FREQUENCY', 'everyMinute'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Status Route
    |--------------------------------------------------------------------------
    |
    | Configuration for the opt-in JSON health endpoint registered via
    | Health::routes() in the host application's routes file. The endpoint is
    | never registered automatically.
    |
    */

    'route' => [
        'uri' => env('ALERTS_ROUTE_URI', 'health'),
        'name' => 'alerts.health',
    ],

];
