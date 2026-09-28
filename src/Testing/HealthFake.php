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
use RoundlyConsulting\Alerts\DataTransferObjects\ScheduleHealthCheckData;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Exceptions\InvalidHealthCheck;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\HealthManager;
use RoundlyConsulting\Alerts\Status\CheckStatus;
use RoundlyConsulting\Alerts\Status\HealthReport;
use RoundlyConsulting\Alerts\Support\AlertSilenceModel;
use RoundlyConsulting\Alerts\Support\HealthCheckModel;

/**
 * Test double for the Health manager, installed by `Health::fake()`. It keeps the
 * registered checks, runs checks without dispatching jobs, sending notifications or
 * writing a row, answers reports and silences from memory, and records every
 * mutating call — through the facade, an injected manager, `for()`, `silences()` or
 * the UsesHealthChecks trait — for the assertions below.
 */
final class HealthFake extends HealthManager
{
    /** @var list<array{key: string, notifiable: string, result: CheckResult, tags: list<string>}> */
    private array $runs = [];

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
        } else {
            $instance = $this->resolveCheck($check);
            $this->register($instance);
        }

        $key = $instance->key();
        $result = $instance->check();

        $this->runs[] = [
            'key' => $key,
            'notifiable' => $this->identify($notifiable),
            'result' => $result,
            'tags' => $instance->tags(),
        ];

        if ($result->status->isAlertable() && ! $this->silenced([$key, ...$instance->tags(), '*'], $notifiable)) {
            $this->alerted[] = $key;
        }

        if ($result->isOk) {
            $this->recovered[] = $key;
        }

        return $result;
    }

    /**
     * Built from the runs this fake recorded — the latest result per notifiable and
     * check — never from the database.
     *
     * @param  list<string>|null  $tags
     */
    public function reportFor(?Model $notifiable, ?array $tags = null): HealthReport
    {
        $scope = $notifiable === null ? null : $this->identify($notifiable);

        $latest = [];

        foreach ($this->runs as $run) {
            if ($scope !== null && $run['notifiable'] !== $scope) {
                continue;
            }

            if ($tags !== null && $tags !== [] && array_intersect($tags, $run['tags']) === []) {
                continue;
            }

            $latest[$run['notifiable'].'|'.$run['key']] = $run;
        }

        $checks = array_map(fn (array $run): CheckStatus => new CheckStatus(
            key: $run['key'],
            name: $this->find($run['key'])?->name() ?? $run['key'],
            status: $run['result']->status->isAlertable() ? $run['result']->status : Status::Ok,
            message: $run['result']->status->isAlertable() ? $run['result']->message : null,
            tags: $run['tags'],
            uptime: $this->uptime($run['key'], $run['notifiable']),
            muted: $this->silenced([$run['key'], ...$run['tags'], '*'], null, $run['notifiable']),
        ), array_values($latest));

        return new HealthReport($checks);
    }

    /**
     * Records the schedule and returns an unsaved row carrying what would be stored.
     */
    public function scheduleFor(Model $notifiable, ScheduleHealthCheckData $data): HealthCheck
    {
        $this->monitored[] = [
            'key' => $data->key(),
            'notifiable' => $this->identify($notifiable),
            'data' => $data,
        ];

        return HealthCheckModel::new()->forceFill([
            'notifiable_type' => $notifiable->getMorphClass(),
            'notifiable_id' => $notifiable->getKey(),
            'health_check' => $data->key(),
            'frequency' => $data->cronFrequency(),
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

        $key = match (true) {
            $check instanceof HealthCheck => $check->health_check,
            $check instanceof Check => $check->key(),
            is_subclass_of($check, Check::class) => (new $check)->key(),
            default => $check,
        };

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
        $this->pruned[] = $days;

        return 0;
    }

    public function assertChecked(string $key): void
    {
        PHPUnit::assertContains($key, array_column($this->runs, 'key'), "The check [$key] was not run.");
    }

    public function assertNothingChecked(): void
    {
        PHPUnit::assertSame([], array_column($this->runs, 'key'), 'Unexpected check runs were recorded.');
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
        $key = $this->keyOf($check);

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
        $key = $this->keyOf($check);

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
        if (config('alerts.silence', true) !== true) {
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

    private function uptime(string $key, string $notifiable): float
    {
        $runs = array_filter(
            $this->runs,
            fn (array $run): bool => $run['key'] === $key && $run['notifiable'] === $notifiable,
        );

        $healthy = array_filter($runs, fn (array $run): bool => ! $run['result']->status->isAlertable());

        return round((count($healthy) / count($runs)) * 100, 2);
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

    private function keyOf(string $check): string
    {
        return is_subclass_of($check, Check::class) ? (new $check)->key() : $check;
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
