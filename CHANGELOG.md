# Changelog

All notable changes to `alerts-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

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
