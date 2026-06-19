# Alerts for Laravel

Schedule recurring health checks against any notifiable model and dispatch throttled alert
notifications when a check fails or recovers.

The package gives you three building blocks:

- **`Check`** — a class you write (e.g. `DiskUsageCheck`, `PingWebsiteCheck`) that performs
  a check and returns a `CheckResult`.
- **`HealthCheck`** — the database record that schedules a `Check` to run at a given cron
  frequency for a specific notifiable owner.
- **`Alert`** — the record created when a health check fails, capturing the `triggered_at`
  and `recovered_at` timestamps plus any meta data.

A single Artisan command, scheduled to run every minute, dispatches a queued job for every
health check that is due.

## Requirements

- PHP 8.3 or 8.4
- Laravel 12 or 13

## Installation

```bash
composer require roundly-consulting/alerts-for-laravel
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag="alerts-migrations"
php artisan migrate
```

Optionally publish the config file:

```bash
php artisan vendor:publish --tag="alerts-config"
```

## Configuration

The published `config/alerts.php` lets you swap the models and job the package uses:

```php
<?php

return [
    // Eloquent model used to store scheduled health checks.
    'health-check' => \RoundlyConsulting\Alerts\HealthCheck::class,

    // Eloquent model used to record alerts and their recovery timestamps.
    'alert' => \RoundlyConsulting\Alerts\Alert::class,

    // Queued job dispatched for each due health check.
    'job' => \RoundlyConsulting\Alerts\Jobs\HealthCheckJob::class,
];
```

| Key | Type | Default | Purpose |
|---|---|---|---|
| `health-check` | `class-string` | `RoundlyConsulting\Alerts\HealthCheck` | Model that schedules checks. |
| `alert` | `class-string` | `RoundlyConsulting\Alerts\Alert` | Model that records alerts. |
| `job` | `class-string` | `RoundlyConsulting\Alerts\Jobs\HealthCheckJob` | Job that runs a due check. |

## Usage

### 1. Define your checks

Register the checks your application supports, typically in a service provider's `boot()`:

```php
use RoundlyConsulting\Alerts\Facades\Health;

Health::check(DiskUsageCheck::class);

// Or register several at once:
Health::checks([
    DiskUsageCheck::class,
    MemoryUsageCheck::class,
]);

// The Health manager is also resolvable from the container:
app(\RoundlyConsulting\Alerts\Health::class)->checks([DiskUsageCheck::class]);
```

### 2. Inspect registered checks

```php
use RoundlyConsulting\Alerts\Facades\Health;

Health::all(); // Collection<string, Check> keyed by check key

$check = Health::find('disk_usage_check');

$check->name();        // "Disk Usage Check"
$check->key();         // "disk_usage_check"
$check->description();  // "Perform Disk Usage Check and trigger notification when necessary."
$check->frequencies(); // ['* * * * *' => 'Every Minute', ...]
```

### 3. Write a check

Extend `RoundlyConsulting\Alerts\Check` and implement `check()` and `notification()`:

```php
use Illuminate\Notifications\Notification;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\CheckResult;

class DiskUsageCheck extends Check
{
    public function check(): CheckResult
    {
        // $this->healthCheck holds the scheduled HealthCheck record and its meta.
        $serverId = $this->healthCheck?->meta['server_id'] ?? null;

        return CheckResult::ok(
            message: 'Disk usage within limits',
            meta: ['server_id' => $serverId],
        );

        // Return CheckResult::failed('Disk almost full', [...]) to trigger an alert.
    }

    public function notification(object $notifiable): Notification
    {
        return new DiskUsageNotification();
    }
}
```

### 4. Prepare the owner model

The model that owns health checks decides who receives alert notifications. Implement
`HasNotifiablesForAlerts` and use the `UsesHealthChecks` trait:

```php
use Closure;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Alerts\Interfaces\HasNotifiablesForAlerts;
use RoundlyConsulting\Alerts\Traits\UsesHealthChecks;

class Team extends Model implements HasNotifiablesForAlerts
{
    use UsesHealthChecks;

    public function forEachNotifiableForAlerts(Closure $callback): void
    {
        $this->members()
            ->where('alerts_enabled', true)
            ->each(fn (Member $member) => $callback($member));
    }
}
```

The trait adds `healthChecks()` and `alerts()` relationships plus a `createHealthCheck()`
helper.

### 5. Schedule a check for an owner

```php
$team = Team::first();

$team->createHealthCheck(
    healthCheckKey: 'disk_usage_check',
    frequency: '*/5 * * * *',
    maxAttempts: 2,
    decayMinutes: 60,
    meta: ['server_id' => '2d4fcdff-9787-49b1-8b73-3d411f80ae1b'],
);
```

This runs `DiskUsageCheck` every 5 minutes and sends at most 2 notifications per hour while
the check is failing.

### 6. Run due checks

Run the command, or schedule it every minute in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('alerts:perform-health-checks')->everyMinute();
```

```bash
php artisan alerts:perform-health-checks
```

The command dispatches `HealthCheckJob` for every health check whose cron frequency is due.

### Events

The host application can listen for:

- `RoundlyConsulting\Alerts\Events\HealthCheckFailed` — dispatched with the `Alert` when a
  check fails.
- `RoundlyConsulting\Alerts\Events\HealthCheckRecovered` — dispatched with the `Alert` when
  a previously failing check passes again.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for what has changed recently.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
