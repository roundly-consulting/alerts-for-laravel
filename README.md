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

- PHP 8.4
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

Optionally publish the config file or translations:

```bash
php artisan vendor:publish --tag="alerts-config"
php artisan vendor:publish --tag="alerts-translations"
```

## Configuration

The published `config/alerts.php` lets you swap the models and job the package uses, register
checks globally, control scheduling, and configure the optional status endpoint:

```php
<?php

return [
    'health-check' => \RoundlyConsulting\Alerts\HealthCheck::class,
    'alert' => \RoundlyConsulting\Alerts\Alert::class,
    'job' => \RoundlyConsulting\Alerts\Jobs\HealthCheckJob::class,

    // Check classes registered globally on boot.
    'checks' => [
        // \RoundlyConsulting\Alerts\Checks\DatabaseCheck::class,
    ],

    // Auto-register the perform command on the scheduler.
    'schedule' => [
        'enabled' => env('ALERTS_SCHEDULE', true),
        'frequency' => env('ALERTS_SCHEDULE_FREQUENCY', 'everyMinute'),
    ],

    // Opt-in JSON status endpoint registered via Health::routes().
    'route' => [
        'uri' => env('ALERTS_ROUTE_URI', 'health'),
        'name' => 'alerts.health',
    ],
];
```

| Key | Type | Default | Env | Purpose |
|---|---|---|---|---|
| `health-check` | `class-string` | `RoundlyConsulting\Alerts\HealthCheck` | — | Model that schedules checks. |
| `alert` | `class-string` | `RoundlyConsulting\Alerts\Alert` | — | Model that records alerts. |
| `job` | `class-string` | `RoundlyConsulting\Alerts\Jobs\HealthCheckJob` | — | Job that runs a due check. |
| `checks` | `array` | `[]` | — | Check classes/instances registered on boot. |
| `schedule.enabled` | `bool` | `true` | `ALERTS_SCHEDULE` | Auto-register the perform command on the scheduler. |
| `schedule.frequency` | `string` | `everyMinute` | `ALERTS_SCHEDULE_FREQUENCY` | Scheduler method used (`everyMinute`, `everyFiveMinutes`, `hourly`, …). |
| `route.uri` | `string` | `health` | `ALERTS_ROUTE_URI` | URI for the opt-in JSON status endpoint. |
| `route.name` | `string` | `alerts.health` | — | Route name for the status endpoint. |

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

#### Built-in checks

The package ships fluent checks for the common cases, each with a default notification so you
don't have to write one:

```php
use RoundlyConsulting\Alerts\Checks\{CacheCheck, DatabaseCheck, HttpPingCheck, QueueCheck, StorageCheck};

Health::check(DatabaseCheck::make('mysql'));
Health::check(CacheCheck::make('redis'));
Health::check(QueueCheck::make()->onQueue('emails')->maxSize(1000));
Health::check(StorageCheck::make('uploads')->minimumBytes(2 * 1024 ** 3));
Health::check(HttpPingCheck::make('https://api.example.com')->timeout(5)->expectStatus(200)->slowerThan(800));
```

| Check | Healthy when | Warns when | Fails when |
|---|---|---|---|
| `DatabaseCheck` | the connection opens | — | the connection is unreachable |
| `CacheCheck` | a sentinel value round-trips | — | the store is unreachable or returns a mismatch |
| `QueueCheck` | the connection resolves | backlog exceeds `maxSize()` | the connection cannot be resolved |
| `StorageCheck` | free space is comfortable | space dips below the warning threshold | space is below `minimumBytes()` |
| `HttpPingCheck` | the expected status returns | the response is slower than `slowerThan()` | the status is unexpected or the request times out |

#### Inline (closure) checks

For one-off checks, define one inline instead of writing a class:

```php
Health::define('redis-up', fn () => Redis::ping() ? CheckResult::ok() : CheckResult::failed('Redis down'))
    ->name('Redis')
    ->throttle(maxAttempts: 1, decayMinutes: 15)
    ->notifyUsing(RedisDownNotification::class);
```

A closure may return a `CheckResult` or a plain `bool`.

#### Result severity

A `CheckResult` carries a `Status` (`ok`, `warning`, `failed`, `skipped`). A `warning` opens an
alert and notifies just like a `failed`; a `skipped` result records nothing. The legacy `isOk`
flag is still available.

```php
use RoundlyConsulting\Alerts\CheckResult;

CheckResult::ok();
CheckResult::warning('Disk filling up');
CheckResult::failed('Disk full');
CheckResult::skipped('Maintenance window');
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

The trait adds `healthChecks()` and `alerts()` relationships plus `createHealthCheck()`,
`monitor()`, and the fluent `monitorCheck()` helper.

### 5. Schedule a check for an owner

Use the fluent builder with preset frequencies and a check class (no magic strings):

```php
$team = Team::first();

$team->monitorCheck(DiskUsageCheck::class)
    ->everyFiveMinutes()
    ->throttle(maxAttempts: 2, decayMinutes: 60)
    ->meta(['server_id' => '2d4fcdff-9787-49b1-8b73-3d411f80ae1b'])
    ->save();
```

Available presets: `everyMinute()`, `everyFiveMinutes()`, `everyTenMinutes()`,
`everyFifteenMinutes()`, `everyThirtyMinutes()`, `hourly()`, `daily()`, `weekly()`,
`monthly()`, plus `frequency('hourly')` and `cron('15 3 * * *')`.

A DTO and the original positional helper remain available:

```php
use RoundlyConsulting\Alerts\DataTransferObjects\ScheduleHealthCheckData;

$team->monitor(new ScheduleHealthCheckData(
    check: DiskUsageCheck::class, frequency: 'hourly', maxAttempts: 2, decayMinutes: 60,
));

// Still supported:
$team->createHealthCheck('disk_usage_check', '*/5 * * * *', 2, 60, [...]);
```

### 6. Run due checks

The command is auto-registered on the scheduler when `schedule.enabled` is `true` (the
default). To wire it yourself instead, disable that flag and add it to `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('alerts:perform-health-checks')->everyMinute();
```

```bash
php artisan alerts:perform-health-checks
```

The command dispatches `HealthCheckJob` for every health check whose cron frequency is due.

### 7. Run a check now

Run a check synchronously against an owner — it performs the same alert/notify/recover side
effects as the queued job and returns the `CheckResult`:

```php
use RoundlyConsulting\Alerts\Facades\Health;

$result = Health::run(DiskUsageCheck::class, $team);
$result->status; // Status::Ok | Warning | Failed | Skipped
```

### 8. Read current status

```php
$report = Health::report();          // HealthReport (optionally scoped: Health::report($team))
$report->overall();                  // worst Status across all checks
$report->isHealthy();                // bool

foreach ($report->checks() as $check) {
    // CheckStatus: key, name, status, lastAlertAt, message
}

Health::status();                    // Status roll-up
```

A CLI summary (non-zero exit when anything is alertable — handy in CI / uptime probes):

```bash
php artisan alerts:status
```

An opt-in JSON endpoint (returns `200` when healthy, `503` otherwise). Register it from your
own routes file so the package never adds routes by default:

```php
use RoundlyConsulting\Alerts\Facades\Health;

Health::routes();          // GET /health  ->  { "status": "...", "checks": [...] }
```

### Testing

Swap the manager for a fake to assert monitoring without dispatching jobs or notifications:

```php
Health::fake();

Health::run(DiskUsageCheck::class, $team);

Health::assertChecked('disk_usage_check');
Health::assertAlerted('disk_usage_check');
Health::assertNothingRecovered();
```

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
