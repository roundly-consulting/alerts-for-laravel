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

    // Master switch for maintenance-window muting + the silence model.
    'silence' => env('ALERTS_SILENCE', true),
    'silence-model' => \RoundlyConsulting\Alerts\AlertSilence::class,

    // Run history + latency tracking, retention, and the run model.
    'history' => [
        'enabled' => env('ALERTS_HISTORY', true),
        'retention_days' => (int) env('ALERTS_HISTORY_RETENTION', 30),
        'model' => \RoundlyConsulting\Alerts\HealthCheckRun::class,
    ],

    // Optional global default escalation policy (data-only).
    'escalation' => [
        // 1 => 'owner', 3 => 'team', 5 => 'oncall',
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
| `silence` | `bool` | `true` | `ALERTS_SILENCE` | Master switch for maintenance-window muting. |
| `silence-model` | `class-string` | `RoundlyConsulting\Alerts\AlertSilence` | — | Model used to persist mute records. |
| `history.enabled` | `bool` | `true` | `ALERTS_HISTORY` | Record every run (status + latency) and auto-schedule pruning. |
| `history.retention_days` | `int` | `30` | `ALERTS_HISTORY_RETENTION` | How long runs are kept before `alerts:prune-runs` deletes them. |
| `history.model` | `class-string` | `RoundlyConsulting\Alerts\HealthCheckRun` | — | Model used to record runs. |
| `escalation` | `array` | `[]` | — | Optional global default escalation policy (threshold ⇒ group). |

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

The same builder accepts a full set of declarative options — flap debounce, recovery
confirmation, per-check timeout, tags, channel routing, and an escalation policy:

```php
$team->monitorCheck(DiskUsageCheck::class)
    ->everyFiveMinutes()
    ->failAfter(3)                       // open only after 3 consecutive failures
    ->recoverAfter(2)                    // close only after 2 consecutive OKs
    ->timeout(5)                         // abort/mark-failed after 5 seconds
    ->tags(['critical', 'db'])           // group + filter
    ->notifyVia(['mail', 'slack'])       // channels for the default notification
    ->notifyVia(['sms'], level: 3)       // override channels at escalation level 3
    ->escalate([1 => 'owner', 3 => 'team', 5 => 'oncall'])
    ->throttle(maxAttempts: 3, decayMinutes: 10)
    ->save();
```

The same options are available on the inline closure builder (`Health::define(...)`).

A DTO and the original positional helper remain available:

```php
use RoundlyConsulting\Alerts\DataTransferObjects\ScheduleHealthCheckData;

$team->monitor(new ScheduleHealthCheckData(
    check: DiskUsageCheck::class, frequency: 'hourly', maxAttempts: 2, decayMinutes: 60,
));

// Still supported:
$team->createHealthCheck('disk_usage_check', '*/5 * * * *', maxAttempts: 2, decayMinutes: 60, tags: ['db']);
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
    // CheckStatus: key, name, status, lastAlertAt, message, tags, uptime, p95LatencyMs, muted
}

Health::status();                    // Status roll-up
Health::report($team, ['critical']); // scope the report to one or more tags
$report->whereTag('db');             // filter an in-memory report
```

A CLI summary (non-zero exit when anything is alertable — handy in CI / uptime probes):

```bash
php artisan alerts:status
php artisan alerts:status --tag=db   # filter by tag
```

An opt-in JSON endpoint (returns `200` when healthy, `503` otherwise). Register it from your
own routes file so the package never adds routes by default:

```php
use RoundlyConsulting\Alerts\Facades\Health;

Health::routes();          // GET /health        -> { "status": "...", "checks": [...] }
// GET /health?tag=critical -> only checks carrying the "critical" tag
```

### 9. Flap detection & recovery confirmation

`failAfter(n)` requires `n` consecutive failures before an alert is opened (and
`HealthCheckFailed` / notifications fire), killing flapping noise. `recoverAfter(n)` mirrors
it: an alert stays open until `n` consecutive OK results confirm recovery. Both default to `1`
(open/close immediately). Per-monitor counters live on the `health_checks` row
(`consecutive_failures` / `consecutive_successes`). Below the threshold the run is still
recorded in history — only the alert side effects are gated.

### 10. Escalation policies

Notify wider audiences as a failure persists. Declare a threshold ⇒ group map with
`escalate([...])`; the keys are consecutive-failure counts and the values are named notifiable
groups your owner model resolves:

```php
use RoundlyConsulting\Alerts\Traits\ResolvesAlertGroups;

class Team extends Model implements HasNotifiablesForAlerts
{
    use UsesHealthChecks; // provides a default notifiablesForAlertGroup()

    public function forEachNotifiableForAlerts(Closure $callback): void { /* default group */ }

    // Override to map named groups to real notifiables:
    public function notifiablesForAlertGroup(string $group): iterable
    {
        return match ($group) {
            'owner'  => [$this->owner],
            'team'   => $this->members,
            'oncall' => $this->onCallEngineers(),
            default  => $this->members,
        };
    }
}
```

Each newly reached level notifies only that level's group(s) and dispatches
`HealthCheckEscalated($alert, $fromLevel, $toLevel)`. The level is tracked on the `alerts`
row (`escalation_level`) and resets to `0` on recovery. The `ResolvesAlertGroups` trait (bundled
into `UsesHealthChecks`) provides a default that returns the default group for any name, so
adopting models need no change unless they want named groups.

### 11. Per-check & per-level routing

`notifyVia(['mail', 'slack'])` sets the channels the bundled default notification delivers on;
`notifyVia(['sms'], level: 3)` overrides them for a specific escalation level. The effective list
resolves in the order *level override → global list → `['mail', 'database']`*. Custom
notifications keep full control of their own `via()`.

### 12. Maintenance windows / muting

Suppress alert notifications during deploys or maintenance while still recording runs:

```php
Health::mute('disk_usage_check', until: now()->addMinutes(30), reason: 'db migration');
Health::isMuted('disk_usage_check');   // true
Health::unmute('disk_usage_check');

Health::mute('critical');              // mute a whole tag
Health::mute('*');                     // mute everything
Health::mute('disk_usage_check', notifiable: $team); // scope the mute to one owner
```

While muted, runs and counters are still maintained but `HealthCheckFailed` /
`HealthCheckRecovered` and notifications are suppressed, and touched alerts are flagged
`meta['muted']` so the status report shows them as muted.

### 13. Run history, uptime & latency

Every executed check records one immutable `HealthCheckRun` (status + wall-clock latency),
queryable off the `HealthCheck` model:

```php
$check = $team->healthChecks()->first();

$check->runs();                            // HasMany, newest first
$check->latestRun();                       // ?HealthCheckRun
$check->uptimePercentage(now()->subDay()); // % of non-alertable runs in the window
$check->p95LatencyMs(now()->subDay());     // 95th-percentile duration in ms
```

Retention is pruned by `alerts:prune-runs` (auto-scheduled daily when history is enabled):

```bash
php artisan alerts:prune-runs --days=30
```

### 14. Per-check timeout

`timeout(5)` bounds a check at five seconds. On CLI with `ext-pcntl` the check is hard-aborted
the moment the budget elapses (via `SIGALRM`); elsewhere (web SAPI / Windows) it is a
best-effort post-hoc report — the check runs to completion and is marked failed if it overran.
A timed-out check flows through the normal failed-result path with a `CheckTimedOut` exception
recorded in meta.

### 15. Exception-safe checks

A check's `check()` never needs a `try/catch` — any thrown exception is converted into a clean
failed result and recorded as a normal alert + run, so a bad check never fails the queue job:

```php
Health::define('flaky', function () {
    throw new RuntimeException('upstream exploded'); // becomes CheckResult::failed()
});

// Or build a result from a caught exception yourself:
CheckResult::fromException($e); // status failed, exception class + bounded trace in meta
```

### Operator commands

```bash
php artisan alerts:check disk_usage_check                  # run one check now, pretty-print result
php artisan alerts:check disk_usage_check --notifiable="App\\Models\\Team:1"  # full side-effect path
php artisan alerts:list --tag=critical                     # inventory: key, name, frequency, last run, status, muted, tags
php artisan alerts:status --tag=db                         # status table, tag-filterable
php artisan alerts:prune-runs --days=30                    # prune run history (also auto-scheduled)
```

`alerts:check` exits `0` for ok/skipped and `1` for an alertable result. Without `--notifiable`
it runs a pure probe (no side effects); with it, the full alert/notify/history path runs.

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
- `RoundlyConsulting\Alerts\Events\HealthCheckEscalated` — dispatched with the `Alert` and the
  `fromLevel` / `toLevel` each time an open alert reaches a new escalation level.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for what has changed recently.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
