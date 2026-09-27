# Changelog

All notable changes to `alerts-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

Initial public release.

### Added

- Recurring health checks for any notifiable model: write a `Check` class or define one inline
  with `Health::define()`, then schedule it per owner on a cron frequency with `monitorCheck()`.
- Built-in `DatabaseCheck`, `CacheCheck`, `QueueCheck`, `StorageCheck` and `HttpPingCheck`, each
  with a default notification.
- `CheckResult` severities (`ok`, `warning`, `failed`, `skipped`) and alerts that record when a
  check fails and when it recovers.
- Throttled alert notifications, flap detection (`failAfter()` / `recoverAfter()`) and per-check
  timeouts; a check that throws is recorded as failed instead of crashing the run.
- Escalation policies by level, per-check and per-level notification channels, and tags.
- Maintenance windows: mute a check until a given time with `Health::mute()`.
- Run history with `uptimePercentage()` and `p95LatencyMs()`, a status report
  (`Health::report()`) and an opt-in JSON status endpoint (`Health::routes()`).
- Artisan commands `alerts:perform-health-checks`, `alerts:check`, `alerts:list`,
  `alerts:status` and `alerts:prune-runs`.
- `HealthCheckFailed`, `HealthCheckRecovered` and `HealthCheckEscalated` events.
- `Health::fake()` with `assertChecked()`, `assertAlerted()` and related assertions.
