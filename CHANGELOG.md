# Changelog

All notable changes to `alerts-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

### Added

- `Health::report()` and `Health::for($owner)->report()` take two optional named arguments:
  `since:` (a moment — only the runs since then count towards `uptime` and `p95LatencyMs`;
  default: the whole retained history, as before) and `scheduledOnly:` (`true` leaves out the
  on-demand rows `run()` creates). `status()` is unchanged.
- `CheckStatus::$meta`: the open alert's raw `meta` (`null` without an open alert), so a host no
  longer re-queries alerts for it. It can hold exception details — sanitise it before showing it
  to users. `CheckStatus::toArray()` includes it; the JSON health endpoint never renders it, with
  or without `alerts.route.details`.
- `CheckStatus::tryFromArray(mixed $row)` and `HealthReport::tryFromArray(mixed $payload)` read
  `toArray()` back — for caches that store arrays only (`cache.serializable_classes` off). A
  payload they cannot read is `null`, a cache miss, never an error; the stored overall `status`
  is ignored and recomputed.
- `Health::silences()->snapshot(?CarbonInterface $at = null)` returns a `SilenceSnapshot`: every
  silence in force at that moment, loaded in one query, whose `covers(HealthCheck $row)` tells
  whether a row is muted by the same rule the run pipeline applies (the row's check key, one of
  its effective tags or `*`; global or scoped to the row's own notifiable). `isEmpty()` says
  whether anything is muted at all, and `Health::silences()->covers($row)` is the one-row
  shortcut. `Health::fake()` answers both from its in-memory silences.
- `Health::silences()->prune(?int $days = null)` (`PruneAlertSilencesAction`) and the
  `alerts:prune-silences {--days=}` command delete silences that ended more than
  `alerts.silence-retention` days ago (`ALERTS_SILENCE_RETENTION`, default `7`, a whole number of
  at least 1 — anything else throws, naming the key). Open-ended, running and future silences are
  never touched; a retention below 1 day throws `InvalidRetention`. The command is scheduled daily
  while `alerts.silence` is on, and `about` shows the retention (`Silences: ON (7 day retention)`).
  The fake records it: `assertSilencesPruned(?int $days = null)` / `assertNoSilencesPruned()`.
- `Check::uniqueBy(): ?array` (and `Health::define(...)->uniqueBy([...])` for inline checks)
  declares a schedule's identity: `null` (the default) keeps today's insert-every-time, `[]` keeps
  one live schedule per notifiable and check, and `['service']` one per notifiable, check and
  `meta.service`. For such a check, `Health::for($owner)->schedule()` (and `monitor()->save()` and
  the `UsesHealthChecks` helpers) updates the existing schedule instead of stacking another: its
  settings are rebuilt from the DTO, only the ones that differ are written (tags compared as sets,
  `meta` regardless of key order), and the flap counters reset only when one did — an identical
  re-schedule writes nothing. Audit the outcome with Eloquent's `wasRecentlyCreated`,
  `wasChanged()`, `getChanges()` and `getPrevious()`. A schedule written before 1.2 is adopted (the
  oldest matching one) the first time it is scheduled again; `unmonitor()` gives the identity up
  so the same thing can be scheduled anew. A discriminator value that is not a scalar or null
  throws `InvalidHealthCheck::invalidIdentity()`.
- `Health::for($owner)->reconfigure(HealthCheck $row, ReconfigureHealthCheckData $changes)`
  (`ReconfigureHealthCheckAction`): change a schedule in place. Every
  `ReconfigureHealthCheckData` setting (`frequency`, `maxAttempts`, `decayMinutes`, `tags`,
  `failAfter`, `recoverAfter`) left `null` stays; `tags: []` clears them, `failAfter: 1` /
  `recoverAfter: 1` remove the setting, and `meta` merges key by key (a `null` value removes the
  key). Same diff rule as `schedule()`: no change is no write and keeps the counters. Another
  notifiable's row is refused with `InvalidHealthCheck`, and moving a `uniqueBy()` schedule onto
  the identity of another live schedule throws the new `ScheduleConflict` (a `RuntimeException`
  naming the check, the row and the identity's meta keys — never their values; map it to 409).
  In a process where the check is not registered the identity cannot be recomputed: the row keeps
  it while its meta stays and gives it up when the meta changes (the next `schedule()` of the new
  identity adopts the row). The fake records it: `assertReconfigured(string $check, ?Model $notifiable = null)` /
  `assertNothingReconfigured()`.
- A new publish-only migration, `add_identity_hash_to_health_checks_table`, adds the unique
  `health_checks.identity_hash` column a `uniqueBy()` schedule upserts on (no backfill).
- A new publish-only migration, `add_identity_hash_to_alert_silences_table`, adds the unique
  `alert_silences.identity_hash` column `mute()` upserts on.

### Changed

- Upgrade: 1.2 ships two new publish-only migrations (`add_identity_hash_to_health_checks_table`,
  `add_identity_hash_to_alert_silences_table`). Run `php artisan vendor:publish
  --tag=alerts-migrations` and `php artisan migrate`. If you edited a published alerts migration,
  `vendor:publish` no longer recognises it and would publish a second copy of the create
  migrations: copy the two new files into `database/migrations` by hand instead.
- `Health::report()` (and with it the `muted` flag the JSON health endpoint renders with
  `alerts.route.details`) now reads `muted` from the silences in force — by the run pipeline's own rule — instead of the
  open alert's `meta.muted` flag. A muted check with no open alert now reads `true`, and an alert
  opened under a silence that has since ended reads `false`. The pipeline still writes `muted` into
  the alert's `meta`.
- `Health::report()` reports `uptime` and `p95LatencyMs` as `null` for a row with no run in the
  window (or none at all, or with `alerts.history.enabled` off); they used to read `100.0` and `0`,
  so a check that never ran looked perfect. `HealthCheck::uptimePercentage()` and `p95LatencyMs()`
  keep returning `100.0` and `0` without history.
- `Health::report()` lists its rows in id order, and builds the whole report in a fixed number of
  queries (at most five) however many rows and runs there are. The p95 is read with a window function, so
  MySQL 8.0 or later is required (MySQL 5.7 is end-of-life); PostgreSQL and SQLite are unaffected.
- `Health::fake()` upserts a `uniqueBy()` schedule in memory with the same diff and counter-reset
  rule, and each later `schedule()` of it returns a fresh copy of the row, as the real manager
  re-reads it; the rows it returns report `wasRecentlyCreated` / `wasChanged()` like real ones.
- `Health::fake()`'s report follows the real one: it honours `since:` and `scheduledOnly:`, reads
  `muted` from its in-memory silences, reports `uptime` as `null` before a monitor's first run, and
  carries the open alert's meta (its `p95LatencyMs` stays `null`: the fake records no durations).
- `Health::silences()->mute()` now updates the silence with the same key and scope (global, or
  the same notifiable) instead of inserting another row: muting again replaces its `reason` and
  `ends_at`, so it extends or shortens the window, and returns that one row. A global and a scoped
  silence of one key stay distinct, and two concurrent mutes still leave one row. A silence a host
  writes straight through the model carries no identity and is never merged. `Health::fake()`
  keeps one in-memory silence per identity the same way (every call is still recorded for
  `assertMuted()`). The new migration collapses the immediate silences already stacked per key
  and scope into one — the newest row, open-ended if any of them was, else until the latest end,
  with the newest reason — so what is muted does not change.
- Maintenance: CI runs the whole suite on MySQL 8 as well as PostgreSQL, the two supported
  databases.

### Fixed

- A skipped check no longer outranks an ok one in `HealthReport::overall()`: a report of ok and
  skipped checks (or only skipped ones) is `ok`, not `skipped`. `Status::severity()` is unchanged.
- `Health::report()` no longer runs five queries per row over the whole run history (an open-alert
  lookup, two counts and two p95 reads each): the JSON endpoint and `alerts:status` call it on
  every hit.

### Security

- A check that throws no longer stores — or mails — the exception's message and stack frames,
  which can carry hosts, SQL and credentials. The run and the alert get the message
  `The check threw RuntimeException; the details are in the application log.` (the new
  `alerts::checks.check_errored` line, English and Slovak) and keep only `meta.exception` (the
  class); `exception_message`, `exception_trace` and the `error` a built-in check caught are left
  out. The throwable — and the error a built-in check catches — is reported to the exception
  handler instead, so it lands in your logs (a timeout is not: its text is stored). A
  check's own message, the package's timeout text and `timed_out_after` are kept, and the
  `CheckResult` a run-now returns still carries everything. Opt back in with
  `alerts.store_exception_details` (`ALERTS_STORE_EXCEPTION_DETAILS=true`, a strict boolean). The
  new `CheckResult::storedMeta()` returns the meta as stored, next to `storedMessage()`.

## 1.1.0 - 2026-10-05

### Added

- `alerts.route.details` (`ALERTS_ROUTE_DETAILS`, default `false`): opt the JSON health endpoint
  back into each check's stored message, tags, last alert time and muted flag.
- `InvalidRetention` (an `InvalidArgumentException`), thrown by `Health::prune()` for a run-history
  retention below 1 day.
- `InvalidHealthCheck::invalidEscalationThreshold()` and `MonitorOptions::validateEscalation()`:
  an escalation policy whose thresholds are not whole numbers of at least 1 is refused.
- `CronSchedule::isDueBetween($after, $until)` tells whether a cron expression is due at any
  minute in that window, and `HealthCheck::isDue()` takes an optional `$since` to ask the same of
  a monitor.

### Changed

- The JSON health endpoint (`Health::routes()`) now renders each check as `key`, `name`,
  `status`, `uptime` and `p95_latency_ms` only. It is unauthenticated unless you add middleware
  (`Health::routes()->middleware('auth.basic')`), and a stored alert message can carry raw
  exception text, so `message`, `tags`, `last_alert_at` and `muted` are no longer exposed by
  default. The status code and the top-level `status` are unchanged, and
  `Health::report()->toArray()` still returns everything. Upgrade: a client that reads those
  fields sets `alerts.route.details` (`ALERTS_ROUTE_DETAILS=true`) to get the previous body.
- Documentation: the README hero image uses an absolute URL, so it renders on Packagist and other
  sites.
- Documentation: `Health::report()` / `status()` cover the on-demand rows `Health::for($owner)->run()`
  creates as well as scheduled monitors — a failed on-demand run counts until a passing re-run of
  the check clears it.
- Documentation: the supported databases are MySQL and PostgreSQL (SQLite for tests); SQL Server
  is not supported — its unique index treats the NULL `open_slot` of recovered alerts as equal.

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
- A monitor `timeout()` that a check swallows is still reported as a timeout: the built-in
  checks' `catch (Throwable)` used to turn it into "unreachable" without `timed_out_after`, and a
  check that caught it and retried could overrun its budget and still report `ok`. A check that
  catches the timeout now fails with the timeout message and `timed_out_after` in its meta.
- `alerts:prune-runs --days=0` (or a negative value) no longer deletes the whole run history:
  `--days` must be a whole number of at least 1 — anything else (`0`, `-1`, `abc`, `1.5`) fails
  the command and deletes nothing, where `abc` used to fall back to the configured retention.
  `Health::prune()` (real and fake) throws the new `InvalidRetention` exception below 1 day,
  the same floor `alerts.history.retention_days` has.
- A `HealthCheckJob` queued before its row was unmonitored no longer runs it — the soft-deleted
  row used to record a run, open an alert and notify. `unmonitor()` now also closes any alert
  still open on the rows it removes (`recovered_at` set, no `HealthCheckRecovered` event), which
  used to stay open forever.
- `Health::fake()` now behaves like the real manager for monitors: a run goes through the
  notifiable's oldest monitor of the check (it used the newest), `for($owner)->monitors()` lists
  what was scheduled (it was always empty), a monitor that never ran is in `report()` as `ok`, and
  `unmonitor()` removes the monitors — and the on-demand one — from later runs and the report,
  returning how many it removed (it returned `0` and changed nothing).
- `alerts:list` shows a check's worst status across the notifiables it is scheduled for (it showed
  an arbitrary one, so one owner's failure could read `Ok`), and marks it muted only for a silence
  that applies to every notifiable — not for one scoped to a single owner.
- `Alert::factory()` creates an open alert the way the pipeline opens one: on its health check's
  notifiable (it used an unrelated one) and holding the check's open slot, so the report and the
  pipeline see it — a failing run follows it instead of opening a second alert. `recovered()`
  clears the slot. A second open factory alert for the same check is now refused by the unique
  index, as in the pipeline.

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
