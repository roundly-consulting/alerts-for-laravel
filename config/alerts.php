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

];
