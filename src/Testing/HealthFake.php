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
use RoundlyConsulting\Alerts\DataTransferObjects\ScheduleHealthCheckData;
use RoundlyConsulting\Alerts\Exceptions\InvalidHealthCheck;
use RoundlyConsulting\Alerts\Exceptions\InvalidRetention;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\HealthManager;
use RoundlyConsulting\Alerts\Status\HealthReport;
use RoundlyConsulting\Alerts\Support\AlertSilenceModel;
use RoundlyConsulting\Alerts\Support\HealthCheckModel;
use RoundlyConsulting\Alerts\Support\MonitorOptions;
use RoundlyConsulting\Alerts\Support\SafeCheck;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Test double for the Health manager, installed by `Health::fake()`. It keeps the
 * registered checks, runs checks without dispatching jobs, sending notifications or
 * writing a row, answers reports and silences from memory, and records every
 * mutating call — through the facade, an injected manager, `for()`, `silences()` or
 * the UsesHealthChecks trait — for the assertions below.
 *
 * A run goes through the same gates as the real pipeline, kept in memory per
 * notifiable and check: a thrown exception or an overrun timeout is a failed result,
 * an alert fires only once `failAfter` consecutive failures are reached (and not while
 * muted), and a recovery is recorded only when an open alert closes after
 * `recoverAfter` consecutive successes. The options come from the row being run, a
 * monitor recorded for the notifiable, or an inline check's `define()`.
 */
final class HealthFake extends HealthManager
{
    /** @var list<string> */
    private array $runs = [];

    /** @var array<string, FakeMonitor> */
    private array $monitors = [];

    /** @var list<string> */
    private array $alerted = [];

    /** @var list<string> */
    private array $recovered = [];

    /** @var list<array{key: string, notifiable: string, data: ScheduleHealthCheckData}> */
    private array $monitored = [];

    /** @var list<array{key: string, notifiable: string}> */
    private array $unmonitored = [];

    /** @var list<AlertSilence> */
    private array $silences = [];

    /** @var list<array{key: string, notifiable: string|null, until: CarbonInterface|null, reason: string|null}> */
    private array $muted = [];

    /** @var list<array{key: string, notifiable: string|null}> */
    private array $unmuted = [];

    private int $dueRuns = 0;

    /** @var list<int|null> */
    private array $pruned = [];

    /**
     * @param  iterable<Check>  $checks  the checks already registered on the real manager
     */
    public function __construct(Container $container, iterable $checks = [])
    {
        parent::__construct($container);

        foreach ($checks as $check) {
            $this->register($check);
        }
    }

    public function runFor(Model $notifiable, string|Check|HealthCheck $check): CheckResult
    {
        if ($check instanceof HealthCheck) {
            if (! $check->isScheduledFor($notifiable)) {
                throw InvalidHealthCheck::notScheduledFor($check, $notifiable);
            }

            $instance = $check->healthCheck();
            $options = $check->options();
            $tags = $check->effectiveTags();
        } else {
            $instance = $this->resolveCheck($check);
            [$options, $rowTags] = $this->optionsFor($notifiable, $instance);
            $tags = array_values(array_unique([...$rowTags, ...$instance->tags()]));
        }

        $key = $instance->key();
        $result = SafeCheck::run($instance, $options->timeout(), $key);

        $this->runs[] = $key;

        $muted = $this->silenced([$key, ...$tags, AlertSilence::GLOBAL_KEY], $notifiable);

        $outcome = $this->monitorFor($notifiable, $key, $instance->name(), $tags)
            ->apply($result, $options, $muted);

        match ($outcome) {
            true => $this->alerted[] = $key,
            false => $this->recovered[] = $key,
            null => null,
        };

        return $result;
    }

    /**
     * Built from the monitors this fake ran — the open alert per notifiable and check,
     * as the real report is — never from the database.
     *
     * @param  list<string>|null  $tags
     */
    public function reportFor(?Model $notifiable, ?array $tags = null): HealthReport
    {
        $scope = $notifiable === null ? null : $this->identify($notifiable);

        $checks = [];

        foreach ($this->monitors as $monitor) {
            if ($scope !== null && $monitor->notifiable !== $scope) {
                continue;
            }

            if ($tags !== null && $tags !== [] && ! $monitor->hasAnyTag($tags)) {
                continue;
            }

            $checks[] = $monitor->status();
        }

        return new HealthReport($checks);
    }

    /**
     * Records the schedule and returns an unsaved row carrying what would be stored.
     */
    public function scheduleFor(Model $notifiable, ScheduleHealthCheckData $data): HealthCheck
    {
        // Validated first, as the real action does: an invalid cron is refused, not recorded.
        $frequency = $data->cronFrequency();

        $this->monitored[] = [
            'key' => $data->key(),
            'notifiable' => $this->identify($notifiable),
            'data' => $data,
        ];

        return HealthCheckModel::new()->forceFill([
            'notifiable_type' => $notifiable->getMorphClass(),
            'notifiable_id' => $notifiable->getKey(),
            'health_check' => $data->key(),
            'frequency' => $frequency,
            'max_attempts' => $data->maxAttempts,
            'decay_minutes' => $data->decayMinutes,
            'tags' => $data->tags === [] ? null : $data->tags,
            'meta' => $data->metaWithOptions(),
        ]);
    }

    public function unscheduleFor(Model $notifiable, string|Check|HealthCheck $check): int
    {
        if ($check instanceof HealthCheck && ! $check->isScheduledFor($notifiable)) {
            throw InvalidHealthCheck::notScheduledFor($check, $notifiable);
        }

        $key = $check instanceof HealthCheck ? $check->health_check : $this->keyFor($check);

        $this->unmonitored[] = ['key' => $key, 'notifiable' => $this->identify($notifiable)];

        return 0;
    }

    /**
     * @return EloquentCollection<int, HealthCheck>
     */
    public function scheduledFor(Model $notifiable): EloquentCollection
    {
        return new EloquentCollection;
    }

    /**
     * Records the mute and keeps the silence in memory (unsaved).
     */
    public function muteFor(
        string $key,
        ?CarbonInterface $until = null,
        ?Model $notifiable = null,
        ?string $reason = null,
    ): AlertSilence {
        $this->muted[] = [
            'key' => $key,
            'notifiable' => $notifiable === null ? null : $this->identify($notifiable),
            'until' => $until,
            'reason' => $reason,
        ];

        $silence = AlertSilenceModel::new()->forceFill([
            'key' => $key,
            'notifiable_type' => $notifiable?->getMorphClass(),
            'notifiable_id' => $notifiable?->getKey(),
            'reason' => $reason,
            'starts_at' => null,
            'ends_at' => $until,
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
        $now = now();

        return ($silence->starts_at === null || $silence->starts_at->lessThanOrEqualTo($now))
            && ($silence->ends_at === null || $silence->ends_at->greaterThanOrEqualTo($now));
    }

    private function silenceScope(AlertSilence $silence): ?string
    {
        return $silence->notifiable_id === null
            ? null
            : $silence->notifiable_type.':'.((string) $silence->notifiable_id);
    }

    /**
     * The options a run uses when it is not given a row: those of the latest monitor
     * recorded for this notifiable and check (the row the real run would go through),
     * else an inline check's `define()`, else the defaults.
     *
     * @return array{0: MonitorOptions, 1: list<string>}
     */
    private function optionsFor(Model $notifiable, Check $check): array
    {
        $scope = $this->identify($notifiable);

        /** @var array<int|string, string> $escalation */
        $escalation = config('alerts.escalation', []);

        foreach (array_reverse($this->monitored) as $monitor) {
            if ($monitor['key'] === $check->key() && $monitor['notifiable'] === $scope) {
                return [MonitorOptions::fromMeta($monitor['data']->metaWithOptions(), $escalation), $monitor['data']->tags];
            }
        }

        $meta = $check instanceof ClosureCheck ? $check->scheduleDefaults()->metaWithOptions() : [];

        return [MonitorOptions::fromMeta($meta, $escalation), []];
    }

    /**
     * @param  list<string>  $tags
     */
    private function monitorFor(Model $notifiable, string $key, string $name, array $tags): FakeMonitor
    {
        $scope = $this->identify($notifiable);

        $monitor = $this->monitors[$scope.'|'.$key] ??= new FakeMonitor($key, $scope, $name, $tags);
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
