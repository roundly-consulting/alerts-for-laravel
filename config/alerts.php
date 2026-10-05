<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\Alerts\AlertSilence;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\HealthCheckRun;
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
    | Key Type
    |--------------------------------------------------------------------------
    |
    | The key type used for the polymorphic notifiable columns on health checks,
    | alerts, and silences. Use "uuid" or "ulid" when your notifiable models use
    | UUID/ULID primary keys, otherwise leave it as "bigint". Your notifiables must
    | share one key type; set this to match them. An unrecognized value throws
    | when the migrations run instead of falling back.
    |
    | Supported: "bigint", "uuid", "ulid"
    |
    */

    'key_type' => env('ALERTS_KEY_TYPE', 'bigint'),

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
    | is a single config line. Disable it to wire the command yourself; the daily
    | `alerts:prune-runs` stays scheduled while run history is enabled.
    | `frequency` names a scheduler method from every minute up (`everyMinute`,
    | `everyFiveMinutes`, `hourly`, `daily`, …); an unknown name throws. A
    | coarser cadence still runs every check: each tick queues the checks that
    | came due at any minute since the previous tick.
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
    | never registered automatically and is unauthenticated unless you chain
    | middleware: Health::routes()->middleware('auth.basic').
    |
    | By default each check renders only its key, name, status, uptime and p95
    | latency. `details` adds the stored alert message (which can carry raw
    | exception text), the tags, the last alert time and the muted flag.
    |
    */

    'route' => [
        'uri' => env('ALERTS_ROUTE_URI', 'health'),
        'name' => 'alerts.health',
        'details' => env('ALERTS_ROUTE_DETAILS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Windows / Muting
    |--------------------------------------------------------------------------
    |
    | When enabled, alert notifications can be suppressed during deploys or
    | maintenance via Health::silences()->mute(). Runs are still recorded while
    | muted. Set the master switch to false to ignore every silence.
    | `silence-model` is the Eloquent model used to persist mute records.
    |
    */

    'silence' => env('ALERTS_SILENCE', true),

    'silence-model' => AlertSilence::class,

    /*
    |--------------------------------------------------------------------------
    | Run History & Latency
    |--------------------------------------------------------------------------
    |
    | Every executed check records an immutable run (status + latency) used for
    | uptime % and p95 latency queries. `retention_days` (a whole number, at least
    | 1 — anything else throws) bounds how long runs are kept; the
    | `alerts:prune-runs` command (auto-scheduled daily whenever history is
    | enabled, whatever `schedule.enabled` says) deletes anything older. `model`
    | is the Eloquent model used for runs.
    |
    */

    'history' => [
        'enabled' => env('ALERTS_HISTORY', true),
        'retention_days' => env('ALERTS_HISTORY_RETENTION', 30),
        'model' => HealthCheckRun::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Escalation
    |--------------------------------------------------------------------------
    |
    | Optional global default escalation policy applied when a check declares
    | none. Data-only: routing to real notifiables is resolved per-notifiable in
    | code via the HasNotifiablesForAlerts::notifiablesForAlertGroup() method.
    |
    */

    'escalation' => [
        // 1 => 'owner', 3 => 'team', 5 => 'oncall',
    ],

];
