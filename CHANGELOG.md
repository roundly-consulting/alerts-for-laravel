# Changelog

All notable changes to `alerts-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

### Added

- `alerts.route.details` (`ALERTS_ROUTE_DETAILS`, default `false`): opt the JSON health endpoint
  back into each check's stored message, tags, last alert time and muted flag.

### Changed

- The JSON health endpoint (`Health::routes()`) now renders each check as `key`, `name`,
  `status`, `uptime` and `p95_latency_ms` only. It is unauthenticated unless you add middleware
  (`Health::routes()->middleware('auth.basic')`), and a stored alert message can carry raw
  exception text, so `message`, `tags`, `last_alert_at` and `muted` are no longer exposed by
  default; set `alerts.route.details` to `true` for the previous body. The status code and the
  top-level `status` are unchanged, and `Health::report()->toArray()` still returns everything.

### Fixed

- A recipient whose notification throws (a bad address, a mail server that is down) is now
  reported to the exception handler and skipped; the recipients after it are still notified, and
  an escalated tier is no longer cut short and never paged again.
- `Health::for($owner)->monitor(SomeCheck::class)`, `schedule()`, `unmonitor(SomeCheck::class)`
  and the fake's `assertMonitored()` / `assertUnmonitored()` now resolve a check class-string
  through the registry, as `run()` does: a check registered with `as('key')` is stored and removed
  under that key (it used to be stored under the class-derived key and fail on every scheduled
  run), a registered check whose constructor needs arguments can be scheduled, and a class that is
  not a check throws `InvalidHealthCheck` instead of a PHP error.
- A cron expression that restricts both the day of the month and the day of the week
  (`0 0 1,15 * MON`) now runs on a day matching either field, as standard cron and Laravel's
  scheduler do; it used to run only on days matching both.
- A stepped single value in a cron field (`5/15`) now steps from that value to the end of the
  field (`5,20,35,50`) instead of matching the value alone, and an empty list segment (`5,`, `,5`,
  `/5`) is rejected with `InvalidCronExpression` instead of being read as "every value".
- A `schedule.frequency` coarser than every minute no longer starves monitors whose cron minute
  falls between two ticks (`everyFiveMinutes` never ran `7 * * * *`, `everyOddHour` never ran
  `@daily`): `Health::runDue()` remembers its last tick in the cache and queues every check that
  came due since, once. `HealthCheck::isDue()` takes an optional `$since` for the same window.
- The auto-scheduled `alerts:perform-health-checks` now runs `onOneServer()` with a 5-minute
  overlap lock: several scheduler hosts no longer queue every due check once each (which tripped
  `failAfter`, `recoverAfter` and escalation early), and a scheduler killed mid-run no longer
  stops monitoring for up to 24 hours.
- `CacheCheck` writes its sentinel under a key unique to the run and removes it afterwards, so
  two runs overlapping on a shared store no longer read each other's value and report a false
  `cache_mismatch` failure.
- `HealthCheck::uptimePercentage()` and `p95LatencyMs()` — and with them `Health::report()` and
  the health endpoint — no longer load every run into memory: uptime is two counts and the p95 is
  one row read at its nearest-rank offset, with the same results.
- An escalation policy with a threshold below 1 (`[0 => 'owner']`) or a list-style policy
  (`['owner', 'team']`) is refused with `InvalidHealthCheck` by `escalate()` on a monitor or an
  inline check and by `ScheduleHealthCheckData`; such a group could never be paged, and while the
  policy was set the default group was not notified either.

## 1.0.1 - 2026-10-04

### Changed

- Maintenance: `composer.json` `homepage` and `support.docs` now point to the documentation site.

### Fixed

- Slovak (`sk`) translations now ship alongside English for every language file.

## 1.0.0 - 2026-10-03

Initial public release.

### Added

- Recurring health checks for any notifiable model: write a `Check` class or define one inline
  with `Health::define()`, then schedule it per owner on a cron frequency with
  `Health::for($owner)->monitor()` (or the `monitorCheck()` trait shortcut).
- Built-in `DatabaseCheck`, `CacheCheck`, `QueueCheck`, `StorageCheck` and `HttpPingCheck`, each
  with a default notification.
- `CheckResult` severities (`ok`, `warning`, `failed`, `skipped`) and alerts that record when a
  check fails and when it recovers.
- Throttled alert notifications, flap detection (`failAfter()` / `recoverAfter()`) and per-check
  timeouts; a check that throws is recorded as failed instead of crashing the run.
- Escalation policies by level, per-check and per-level notification channels, and tags.
- Maintenance windows: `Health::silences()->mute()` / `unmute()` / `isMuted()` / `active()`.
- Run history with `uptimePercentage()` and `p95LatencyMs()`, a status report
  (`Health::report()`, `Health::for($owner)->report()`) and an opt-in JSON status endpoint
  (`Health::routes()`).
- One public API in three layers: the `Health` facade, the injectable `HealthManager` behind it,
  and an action per use case. `Health::for($owner)` scopes `run()` / `report()` / `status()` /
  `monitor()` / `schedule()` / `unmonitor()` / `monitors()` to one owner and refuses another
  owner's rows; `Health::runDue()` and `Health::prune()` expose what the scheduler commands do.
- Artisan commands `alerts:perform-health-checks`, `alerts:check`, `alerts:list`,
  `alerts:status` and `alerts:prune-runs`.
- `HealthCheckFailed`, `HealthCheckRecovered` and `HealthCheckEscalated` events.
- `Health::fake()`: a `HealthManager` subtype that also takes over dependency injection, keeps
  the registered checks, answers reports and silences from memory, and records every call —
  including through the `UsesHealthChecks` trait — with `assertChecked/Alerted/Recovered/`
  `Monitored/Unmonitored/Muted/Unmuted/RanDue/Pruned()` and an `assertNothing*()` for each.

### Changed

- The facade root is now `RoundlyConsulting\Alerts\HealthManager` (was `…\Alerts\Health`), bound
  by class name only — the `'health'` container key is gone.
- `Health::run($check, $owner)` → `Health::for($owner)->run($check)`;
  `Health::report($owner, $tags)` → `Health::for($owner)->report($tags)`; `Health::report()` and
  `Health::status()` now take only `?array $tags`.
- `Health::mute/unmute/isMuted()` → `Health::silences()->mute/unmute/isMuted()`; the
  `notifiable:` argument is now `for:`, and `unmute()` returns the number of silences lifted.
- `PendingScheduledCheck` is built by `Health::for($owner)->monitor()`; its constructor is
  internal. `resolveCheck()` and `register()` on the manager are internal.
- `alerts:perform-health-checks` prints how many checks it queued.
- `Health::for($owner)->run()` never schedules anything: without a scheduled row it keeps an
  on-demand row (`frequency` null) the scheduler never queues and `monitors()` does not list. It
  accepts a registered key (the only name of an inline check), runs the registered instance for a
  class-string and never replaces a registered check.
- `Check::as($key)` registers one check class several times under distinct keys.
- Cron expressions accept day and month names and are validated on save; a stored row the
  scheduler cannot evaluate is skipped and reported instead of stopping `runDue()`.
- An inline check's `define()` throttle and options apply to its run-now row and seed
  `monitor($key)`.
- Tag filters (`report()`, `status()`, `alerts:status --tag`, `/health?tag=`) match a check's own
  `tags()`; `alerts:list` shows tag and global silences as muted.
- An open alert follows the latest failing result (status, message, muted flag); a scheduled
  check has at most one open alert even when runs overlap, and a recovery is announced once.
- Run and alert messages are `text` columns, stored up to 1000 characters.
- The default notification includes the failure reason.
- A per-check timeout keeps a queue worker's own job-timeout alarm.
- `Health::fake()` applies the pipeline's gates: exceptions become failed results, `failAfter` /
  `recoverAfter` apply, and a healthy run with nothing open records no recovery.
- `alerts:prune-runs` is scheduled whenever history is enabled, also with `schedule.enabled` off;
  the switches accept `1`/`true`/`on`/`yes`.
