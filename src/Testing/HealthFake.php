<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Testing;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert as PHPUnit;
use RoundlyConsulting\Alerts\AlertSilence;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\CheckResult;
use RoundlyConsulting\Alerts\Checks\ClosureCheck;
use RoundlyConsulting\Alerts\DataTransferObjects\ReconfigureHealthCheckData;
use RoundlyConsulting\Alerts\DataTransferObjects\ScheduleHealthCheckData;
use RoundlyConsulting\Alerts\Exceptions\InvalidHealthCheck;
use RoundlyConsulting\Alerts\Exceptions\InvalidRetention;
use RoundlyConsulting\Alerts\Exceptions\ScheduleConflict;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\HealthManager;
use RoundlyConsulting\Alerts\Status\HealthReport;
use RoundlyConsulting\Alerts\Support\AlertsConfig;
use RoundlyConsulting\Alerts\Support\AlertSilenceModel;
use RoundlyConsulting\Alerts\Support\HealthCheckModel;
use RoundlyConsulting\Alerts\Support\MonitorOptions;
use RoundlyConsulting\Alerts\Support\SafeCheck;
use RoundlyConsulting\Alerts\Support\ScheduleState;
use RoundlyConsulting\Alerts\Support\SilenceMatcher;
use RoundlyConsulting\Alerts\Support\SilenceSnapshot;
use RoundlyConsulting\PackageToolkit\Support\Config;
use WeakMap;

/**
 * Test double for the Health manager, installed by `Health::fake()`. It keeps the
 * registered checks, runs checks without dispatching jobs, sending notifications or
 * writing a row, answers reports and silences from memory, and records every
 * mutating call — through the facade, an injected manager, `for()`, `silences()` or
 * the UsesHealthChecks trait — for the assertions below.
 *
 * A run goes through the same gates as the real pipeline, kept in memory per row the
 * real manager would have written — each scheduled monitor, plus one on-demand monitor per
 * notifiable and check: a thrown exception or an overrun timeout is a failed result,
 * an alert fires only once `failAfter` consecutive failures are reached (and not while
 * muted), and a recovery is recorded only when an open alert closes after
 * `recoverAfter` consecutive successes. A run uses the row it is given, else the
 * notifiable's OLDEST live monitor for the check (as the real run-now does), else an
 * on-demand monitor with an inline check's `define()` options. Monitors are listed by
 * `monitors()` and reported before they ever run; `unmonitor()` removes them, with the
 * on-demand monitor, exactly as the real one soft-deletes the rows.
 */
final class HealthFake extends HealthManager
{
    /** @var list<string> */
    private array $runs = [];

    /**
     * Keyed by the row they stand for: `schedule#n` for a monitor, `<notifiable>|<key>`
     * for the on-demand row (or a database row run directly).
     *
     * @var array<string, FakeMonitor>
     */
    private array $monitors = [];

    /**
     * The monitors still scheduled, oldest first — the rows a real `schedule()` would
     * have written and no `unmonitor()` has removed.
     *
     * @var array<string, array{scope: string, key: string, row: HealthCheck, identity: string|null}>
     */
    private array $schedules = [];

    private int $scheduled = 0;

    /** @var list<string> */
    private array $alerted = [];

    /** @var list<string> */
    private array $recovered = [];

    /** @var list<array{key: string, notifiable: string}> */
    private array $monitored = [];

    /** @var list<array{key: string, notifiable: string}> */
    private array $unmonitored = [];

    /** @var list<array{key: string, notifiable: string}> */
    private array $reconfigured = [];

    /**
     * Every row instance handed out for a live schedule — the one `schedule()` created and the
     * fresh copy each later upsert returns — mapped to its schedule.
     *
     * @var WeakMap<HealthCheck, string>
     */
    private WeakMap $handles;

    /** @var list<AlertSilence> */
    private array $silences = [];

    /** @var list<array{key: string, notifiable: string|null, until: CarbonInterface|null, reason: string|null}> */
    private array $muted = [];

    /** @var list<array{key: string, notifiable: string|null}> */
    private array $unmuted = [];

    private int $dueRuns = 0;

    /** @var list<int|null> */
    private array $pruned = [];

    /** @var list<int|null> */
    private array $prunedSilences = [];

    /**
     * @param  iterable<Check>  $checks  the checks already registered on the real manager
     */
    public function __construct(Container $container, iterable $checks = [])
    {
        parent::__construct($container);

        $this->handles = new WeakMap;

        foreach ($checks as $check) {
            $this->register($check);
        }
    }

    public function runFor(Model $notifiable, string|Check|HealthCheck $check): CheckResult
    {
        $scope = $this->identify($notifiable);

        if ($check instanceof HealthCheck) {
            if (! $check->isScheduledFor($notifiable)) {
                throw InvalidHealthCheck::notScheduledFor($check, $notifiable);
            }

            $instance = $check->healthCheck();
            $row = $check;
            $id = $this->scheduleOf($check) ?? $scope.'|'.$instance->key();
        } else {
            $instance = $this->resolveCheck($check);
            $id = $this->oldestSchedule($scope, $instance->key());
            $row = $id === null ? null : $this->schedules[$id]['row'];
            $id ??= $scope.'|'.$instance->key();
        }

        if ($row !== null) {
            $options = $row->options();
            $tags = array_values(array_unique([...$row->effectiveTags(), ...$instance->tags()]));
        } else {
            /** @var array<int|string, string> $escalation */
            $escalation = config('alerts.escalation', []);
            $meta = $instance instanceof ClosureCheck ? $instance->scheduleDefaults()->metaWithOptions() : [];

            $options = MonitorOptions::fromMeta($meta, $escalation);
            $tags = $instance->tags();
        }

        $key = $instance->key();
        $result = SafeCheck::run($instance, $options->timeout(), $key);

        $this->runs[] = $key;

        $row ??= $this->onDemandRow($notifiable, $key);
        $muted = $this->covered($row, $instance);

        $outcome = $this->monitorFor($id, $scope, $key, $instance->name(), $tags, $row)
            ->apply($result, $options, $muted);

        match ($outcome) {
            true => $this->alerted[] = $key,
            false => $this->recovered[] = $key,
            null => null,
        };

        return $result;
    }

    /**
     * Built from this fake's monitors — every live schedule, run or not, and every
     * on-demand monitor, each with its open alert, as the real report is — never from the
     * database. Uptime counts the runs since `$since`; `muted` is decided by the silences in
     * memory with the runner's rule; there is no p95 (the fake records no durations).
     *
     * @param  list<string>|null  $tags
     */
    public function reportFor(
        ?Model $notifiable,
        ?array $tags = null,
        ?CarbonInterface $since = null,
        bool $scheduledOnly = false,
    ): HealthReport {
        $scope = $notifiable === null ? null : $this->identify($notifiable);
        $silences = $this->silenceSnapshotFor(now());

        $checks = [];

        foreach ($this->monitors as $id => $monitor) {
            if ($scope !== null && $monitor->notifiable !== $scope) {
                continue;
            }

            if ($scheduledOnly && ! isset($this->schedules[$id])) {
                continue;
            }

            if ($tags !== null && $tags !== [] && ! $monitor->hasAnyTag($tags)) {
                continue;
            }

            $checks[] = $monitor->status($since, $silences->covers($monitor->row));
        }

        return new HealthReport($checks);
    }

    /**
     * Records the schedule, keeps it as a live monitor, and returns an unsaved row carrying
     * what would be stored. For a check that declares `uniqueBy()` it upserts in memory, with
     * the real diff and counter-reset rule: the same identity again returns that row, changed
     * only where the settings differ.
     */
    public function scheduleFor(Model $notifiable, ScheduleHealthCheckData $data): HealthCheck
    {
        // Validated first, as the real action does: an invalid cron or identity is refused, not recorded.
        $frequency = $data->cronFrequency();
        $key = $data->key();
        $scope = $this->identify($notifiable);
        $settings = ScheduleState::fromData($data, $frequency);
        $uniqueBy = ScheduleState::uniqueBy($this, $data->check, $key);
        $identity = $uniqueBy === null
            ? null
            : ScheduleState::identity($notifiable->getMorphClass(), $notifiable->getKey(), $key, $uniqueBy, $data->metaWithOptions());

        $this->monitored[] = ['key' => $key, 'notifiable' => $scope];

        $held = $identity === null ? null : $this->scheduleHolding($identity);

        if ($held !== null) {
            // A fresh copy, as the real holder is a fresh read: only this call's changes count.
            $row = clone $this->schedules[$held]['row'];
            $row->wasRecentlyCreated = false;
            $row->syncChanges();
            $this->handles[$row] = $held;

            $this->apply($held, ScheduleState::changes($row, $settings), $row);

            return $row;
        }

        $row = HealthCheckModel::new()->forceFill([
            'notifiable_type' => $notifiable->getMorphClass(),
            'notifiable_id' => $notifiable->getKey(),
            'health_check' => $key,
            ...$settings,
            'identity_hash' => $identity,
        ]);
        $row->syncOriginal();
        $row->wasRecentlyCreated = true;

        $id = 'schedule#'.(++$this->scheduled);

        $this->schedules[$id] = ['scope' => $scope, 'key' => $key, 'row' => $row, 'identity' => $identity];
        $this->handles[$row] = $id;
        $this->monitors[$id] = new FakeMonitor($key, $scope, $this->find($key)?->name() ?? $key, $row->effectiveTags(), $row);

        return $row;
    }

    /**
     * Records the reconfigure and merges the changes into the row in memory — never the
     * database — with the real diff, counter-reset and identity rules: another notifiable's row
     * is refused, and moving onto another schedule's identity throws ScheduleConflict. Neither
     * refusal is recorded.
     */
    public function reconfigureFor(Model $notifiable, HealthCheck $row, ReconfigureHealthCheckData $changes): HealthCheck
    {
        if (! $row->isScheduledFor($notifiable)) {
            throw InvalidHealthCheck::notScheduledFor($row, $notifiable);
        }

        $settings = ScheduleState::merged($row, $changes);
        $diff = ScheduleState::changes($row, $settings);
        $id = $this->scheduleOf($row);

        if ($diff !== [] && $id !== null) {
            $diff['identity_hash'] = $this->movedIdentity($id, $row, $settings, array_key_exists('meta', $diff));
        }

        $this->reconfigured[] = ['key' => $row->health_check, 'notifiable' => $this->identify($notifiable)];

        if ($diff === []) {
            return $row;
        }

        if ($id === null) {
            $row->forceFill($diff)->syncChanges()->syncOriginal();

            return $row;
        }

        $this->apply($id, $diff, $row);

        return $row;
    }

    /**
     * Records the unschedule and removes what the real one soft-deletes: every live
     * monitor of the check for the notifiable plus its on-demand monitor, or the one row
     * given. A database row the fake did not schedule is left alone (the fake never
     * writes), so it counts nothing.
     */
    public function unscheduleFor(Model $notifiable, string|Check|HealthCheck $check): int
    {
        if ($check instanceof HealthCheck && ! $check->isScheduledFor($notifiable)) {
            throw InvalidHealthCheck::notScheduledFor($check, $notifiable);
        }

        $scope = $this->identify($notifiable);
        $key = $check instanceof HealthCheck ? $check->health_check : $this->keyFor($check);

        $this->unmonitored[] = ['key' => $key, 'notifiable' => $scope];

        if ($check instanceof HealthCheck) {
            $id = $this->scheduleOf($check);

            return $id === null ? 0 : $this->forget([$id]);
        }

        $ids = array_keys(array_filter(
            $this->schedules,
            fn (array $schedule): bool => $schedule['scope'] === $scope && $schedule['key'] === $key,
        ));

        return $this->forget([...$ids, $scope.'|'.$key]);
    }

    /**
     * The notifiable's live monitors, oldest first, as the unsaved rows `schedule()` returned.
     *
     * @return EloquentCollection<int, HealthCheck>
     */
    public function scheduledFor(Model $notifiable): EloquentCollection
    {
        $scope = $this->identify($notifiable);

        $rows = [];

        foreach ($this->schedules as $schedule) {
            if ($schedule['scope'] === $scope) {
                $rows[] = $schedule['row'];
            }
        }

        return new EloquentCollection($rows);
    }

    /**
     * Records the mute and keeps the silence in memory (unsaved) — one per identity, as the
     * real upsert does: muting the same key and scope again updates that silence.
     */
    public function muteFor(
        string $key,
        ?CarbonInterface $until = null,
        ?Model $notifiable = null,
        ?string $reason = null,
    ): AlertSilence {
        $scope = $notifiable === null ? null : $this->identify($notifiable);

        $this->muted[] = [
            'key' => $key,
            'notifiable' => $scope,
            'until' => $until,
            'reason' => $reason,
        ];

        $window = ['reason' => $reason, 'starts_at' => null, 'ends_at' => $until];

        foreach ($this->silences as $silence) {
            if ($silence->key === $key && $silence->starts_at === null && $this->silenceScope($silence) === $scope) {
                return $silence->forceFill($window);
            }
        }

        $silence = AlertSilenceModel::new()->forceFill([
            'key' => $key,
            'notifiable_type' => $notifiable?->getMorphClass(),
            'notifiable_id' => $notifiable?->getKey(),
            ...$window,
        ]);

        $this->silences[] = $silence;

        return $silence;
    }

    public function unmuteFor(string $key, ?Model $notifiable = null): int
    {
        $scope = $notifiable === null ? null : $this->identify($notifiable);

        $this->unmuted[] = ['key' => $key, 'notifiable' => $scope];

        $kept = array_values(array_filter(
            $this->silences,
            fn (AlertSilence $silence): bool => $silence->key !== $key || $this->silenceScope($silence) !== $scope,
        ));

        $lifted = count($this->silences) - count($kept);
        $this->silences = $kept;

        return $lifted;
    }

    public function mutedFor(string $key, ?Model $notifiable = null): bool
    {
        return $this->silenced([$key], $notifiable);
    }

    /**
     * @return EloquentCollection<int, AlertSilence>
     */
    public function silencesFor(?Model $notifiable = null): EloquentCollection
    {
        $scope = $notifiable === null ? null : $this->identify($notifiable);

        return new EloquentCollection(array_values(array_filter(
            $this->silences,
            fn (AlertSilence $silence): bool => $this->isActive($silence)
                && ($scope === null || in_array($this->silenceScope($silence), [null, $scope], true)),
        )));
    }

    /**
     * Built from the silences in memory, never the database.
     */
    public function silenceSnapshotFor(CarbonInterface $at): SilenceSnapshot
    {
        if (! Config::boolean('alerts.silence', true)) {
            return new SilenceSnapshot;
        }

        return new SilenceSnapshot(array_filter(
            $this->silences,
            fn (AlertSilence $silence): bool => SilenceMatcher::isActive($silence, $at),
        ));
    }

    /**
     * Records the prune and drops the silences in memory that ended before the retention window.
     */
    public function pruneSilencesFor(?int $days = null): int
    {
        // Refused before it is recorded, as the real action refuses it before deleting.
        if ($days !== null && $days < 1) {
            throw InvalidRetention::silenceDays($days);
        }

        $this->prunedSilences[] = $days;

        $cutoff = now()->subDays($days ?? AlertsConfig::silenceRetentionDays());

        $kept = array_values(array_filter(
            $this->silences,
            fn (AlertSilence $silence): bool => $silence->ends_at === null || $silence->ends_at->greaterThanOrEqualTo($cutoff),
        ));

        $pruned = count($this->silences) - count($kept);
        $this->silences = $kept;

        return $pruned;
    }

    public function runDue(): int
    {
        $this->dueRuns++;

        return 0;
    }

    public function prune(?int $days = null): int
    {
        // Refused before it is recorded, as the real action refuses it before deleting.
        if ($days !== null && $days < 1) {
            throw InvalidRetention::days($days);
        }

        $this->pruned[] = $days;

        return 0;
    }

    public function assertChecked(string $key): void
    {
        PHPUnit::assertContains($key, $this->runs, "The check [$key] was not run.");
    }

    public function assertNothingChecked(): void
    {
        PHPUnit::assertSame([], $this->runs, 'Unexpected check runs were recorded.');
    }

    public function assertAlerted(string $key): void
    {
        PHPUnit::assertContains($key, $this->alerted, "No alert was recorded for [$key].");
    }

    public function assertNothingAlerted(): void
    {
        PHPUnit::assertSame([], $this->alerted, 'Unexpected alerts were recorded.');
    }

    public function assertRecovered(string $key): void
    {
        PHPUnit::assertContains($key, $this->recovered, "No recovery was recorded for [$key].");
    }

    public function assertNothingRecovered(): void
    {
        PHPUnit::assertSame([], $this->recovered, 'Unexpected recoveries were recorded.');
    }

    /**
     * @param  string  $check  a Check class-string or key
     */
    public function assertMonitored(string $check, ?Model $notifiable = null): void
    {
        $key = $this->keyFor($check);

        PHPUnit::assertTrue(
            $this->recorded($this->monitored, $key, $notifiable),
            "The check [$key] was not scheduled".$this->against($notifiable).'.',
        );
    }

    public function assertNothingMonitored(): void
    {
        PHPUnit::assertSame([], $this->monitored, 'Unexpected schedules were recorded.');
    }

    /**
     * @param  string  $check  a Check class-string or key
     */
    public function assertUnmonitored(string $check, ?Model $notifiable = null): void
    {
        $key = $this->keyFor($check);

        PHPUnit::assertTrue(
            $this->recorded($this->unmonitored, $key, $notifiable),
            "The check [$key] was not unscheduled".$this->against($notifiable).'.',
        );
    }

    public function assertNothingUnmonitored(): void
    {
        PHPUnit::assertSame([], $this->unmonitored, 'Unexpected unschedules were recorded.');
    }

    /**
     * @param  string  $check  a Check class-string or key
     */
    public function assertReconfigured(string $check, ?Model $notifiable = null): void
    {
        $key = $this->keyFor($check);

        PHPUnit::assertTrue(
            $this->recorded($this->reconfigured, $key, $notifiable),
            "The check [$key] was not reconfigured".$this->against($notifiable).'.',
        );
    }

    public function assertNothingReconfigured(): void
    {
        PHPUnit::assertSame([], $this->reconfigured, 'Unexpected reconfigurations were recorded.');
    }

    public function assertMuted(string $key, ?Model $notifiable = null): void
    {
        PHPUnit::assertTrue(
            $this->recorded($this->muted, $key, $notifiable),
            "No mute was recorded for [$key]".$this->against($notifiable).'.',
        );
    }

    public function assertNothingMuted(): void
    {
        PHPUnit::assertSame([], $this->muted, 'Unexpected mutes were recorded.');
    }

    public function assertUnmuted(string $key, ?Model $notifiable = null): void
    {
        PHPUnit::assertTrue(
            $this->recorded($this->unmuted, $key, $notifiable),
            "No unmute was recorded for [$key]".$this->against($notifiable).'.',
        );
    }

    public function assertNothingUnmuted(): void
    {
        PHPUnit::assertSame([], $this->unmuted, 'Unexpected unmutes were recorded.');
    }

    public function assertRanDue(?int $times = null): void
    {
        if ($times === null) {
            PHPUnit::assertGreaterThan(0, $this->dueRuns, 'Due checks were never run.');

            return;
        }

        PHPUnit::assertSame($times, $this->dueRuns, "Due checks ran {$this->dueRuns} time(s), expected {$times}.");
    }

    public function assertNothingRanDue(): void
    {
        PHPUnit::assertSame(0, $this->dueRuns, 'Due checks were run unexpectedly.');
    }

    /**
     * @param  int|null  $days  when given, the retention the prune was asked for
     */
    public function assertPruned(?int $days = null): void
    {
        PHPUnit::assertNotSame([], $this->pruned, 'Run history was never pruned.');

        if ($days !== null) {
            PHPUnit::assertContains($days, $this->pruned, "Run history was not pruned with [$days] day(s).");
        }
    }

    public function assertNothingPruned(): void
    {
        PHPUnit::assertSame([], $this->pruned, 'Run history was pruned unexpectedly.');
    }

    /**
     * @param  int|null  $days  when given, the retention the silence prune was asked for
     */
    public function assertSilencesPruned(?int $days = null): void
    {
        PHPUnit::assertNotSame([], $this->prunedSilences, 'Silences were never pruned.');

        if ($days !== null) {
            PHPUnit::assertContains($days, $this->prunedSilences, "Silences were not pruned with [$days] day(s).");
        }
    }

    public function assertNoSilencesPruned(): void
    {
        PHPUnit::assertSame([], $this->prunedSilences, 'Silences were pruned unexpectedly.');
    }

    /**
     * @param  list<string>  $keys
     */
    private function silenced(array $keys, ?Model $notifiable, ?string $scope = null): bool
    {
        if (! Config::boolean('alerts.silence', true)) {
            return false;
        }

        $scope ??= $notifiable === null ? null : $this->identify($notifiable);

        foreach ($this->silences as $silence) {
            if (! in_array($silence->key, $keys, true) || ! $this->isActive($silence)) {
                continue;
            }

            if ($scope === null || in_array($this->silenceScope($silence), [null, $scope], true)) {
                return true;
            }
        }

        return false;
    }

    private function isActive(AlertSilence $silence): bool
    {
        return SilenceMatcher::isActive($silence, now());
    }

    /**
     * The runner's mute gate over the silences in memory.
     */
    private function covered(HealthCheck $row, Check $check): bool
    {
        if (! Config::boolean('alerts.silence', true)) {
            return false;
        }

        foreach ($this->silences as $silence) {
            if ($this->isActive($silence) && SilenceMatcher::covers($silence, $row, $check)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The on-demand row the real run-now would have seeded for the notifiable and check —
     * unsaved: the fake never writes.
     */
    private function onDemandRow(Model $notifiable, string $key): HealthCheck
    {
        return HealthCheckModel::new()->forceFill([
            'notifiable_type' => $notifiable->getMorphClass(),
            'notifiable_id' => $notifiable->getKey(),
            'health_check' => $key,
            'frequency' => null,
        ]);
    }

    private function silenceScope(AlertSilence $silence): ?string
    {
        return $silence->notifiable_id === null
            ? null
            : $silence->notifiable_type.':'.((string) $silence->notifiable_id);
    }

    /**
     * The notifiable's oldest live monitor of the check — the row the real run-now goes
     * through — or null when there is none.
     */
    private function oldestSchedule(string $scope, string $key): ?string
    {
        foreach ($this->schedules as $id => $schedule) {
            if ($schedule['scope'] === $scope && $schedule['key'] === $key) {
                return $id;
            }
        }

        return null;
    }

    /**
     * The live schedule holding an identity, or null.
     */
    private function scheduleHolding(string $identity): ?string
    {
        foreach ($this->schedules as $id => $schedule) {
            if ($schedule['identity'] === $identity) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Write changed settings into the row instance the caller holds and the schedule's own,
     * as a save would, and start its counters over; an unchanged schedule is left as it is.
     *
     * @param  array<string, mixed>  $changes
     */
    private function apply(string $id, array $changes, HealthCheck $handle): void
    {
        if ($changes === []) {
            return;
        }

        $row = $this->schedules[$id]['row'];

        foreach ($handle === $row ? [$row] : [$handle, $row] as $instance) {
            $instance->forceFill($changes)->syncChanges()->syncOriginal();
        }

        $this->monitors[$id]->resetCounters();
        $this->monitors[$id]->describe($this->find($row->health_check)?->name() ?? $row->health_check, $row->effectiveTags());
    }

    /**
     * The identity a reconfigured schedule moves to, refusing one another schedule holds. For a
     * check that is not registered it is kept while the meta stays and given up when it changes,
     * as the real reconfigure does.
     *
     * @param  array<string, mixed>  $settings
     *
     * @throws ScheduleConflict
     */
    private function movedIdentity(string $id, HealthCheck $row, array $settings, bool $metaChanged): ?string
    {
        $check = $this->find($row->health_check);

        if ($check === null) {
            if ($metaChanged) {
                $this->schedules[$id]['identity'] = null;
            }

            return $this->schedules[$id]['identity'];
        }

        $current = $this->schedules[$id]['identity'];

        $uniqueBy = $check->uniqueBy();

        $identity = $uniqueBy === null || $settings['frequency'] === null
            ? null
            : ScheduleState::identity(
                $row->notifiable_type,
                $row->notifiable_id,
                $row->health_check,
                $uniqueBy,
                is_array($settings['meta']) ? $settings['meta'] : null,
            );

        if ($identity !== null && $identity !== $current && $this->scheduleHolding($identity) !== null) {
            throw ScheduleConflict::for($row, $uniqueBy);
        }

        $this->schedules[$id]['identity'] = $identity;

        return $identity;
    }

    /**
     * The live monitor a row returned by `schedule()` stands for, or null for any other row.
     */
    private function scheduleOf(HealthCheck $row): ?string
    {
        $id = $this->handles[$row] ?? null;

        return $id !== null && isset($this->schedules[$id]) ? $id : null;
    }

    /**
     * @param  list<string>  $ids
     * @return int how many monitors were removed
     */
    private function forget(array $ids): int
    {
        $removed = 0;

        foreach ($ids as $id) {
            if (isset($this->monitors[$id])) {
                $removed++;
            }

            unset($this->schedules[$id], $this->monitors[$id]);
        }

        return $removed;
    }

    /**
     * @param  list<string>  $tags
     */
    private function monitorFor(string $id, string $scope, string $key, string $name, array $tags, HealthCheck $row): FakeMonitor
    {
        $monitor = $this->monitors[$id] ??= new FakeMonitor($key, $scope, $name, $tags, $row);
        $monitor->describe($name, $tags);

        return $monitor;
    }

    /**
     * @param  list<array{key: string, notifiable: string|null}>  $records
     */
    private function recorded(array $records, string $key, ?Model $notifiable): bool
    {
        $scope = $notifiable === null ? null : $this->identify($notifiable);

        foreach ($records as $record) {
            if ($record['key'] === $key && ($scope === null || $record['notifiable'] === $scope)) {
                return true;
            }
        }

        return false;
    }

    private function against(?Model $notifiable): string
    {
        return $notifiable === null ? '' : ' for ['.$this->identify($notifiable).']';
    }

    private function identify(Model $notifiable): string
    {
        return $notifiable->getMorphClass().':'.((string) $notifiable->getKey());
    }
}
