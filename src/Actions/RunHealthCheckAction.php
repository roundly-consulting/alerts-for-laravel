<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\Alerts\AlertSilence;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\CheckResult;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Events\HealthCheckEscalated;
use RoundlyConsulting\Alerts\Events\HealthCheckFailed;
use RoundlyConsulting\Alerts\Events\HealthCheckRecovered;
use RoundlyConsulting\Alerts\Exceptions\CheckTimedOut;
use RoundlyConsulting\Alerts\Exceptions\InvalidNotifiableForHealthCheck;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Support\AlertModel;
use RoundlyConsulting\Alerts\Support\AlertSilenceModel;
use RoundlyConsulting\Alerts\Support\HealthCheckRunModel;
use RoundlyConsulting\Alerts\Support\MonitorOptions;
use RoundlyConsulting\Alerts\Support\Timeout;
use Throwable;

/**
 * Runs a single scheduled health check and applies its side effects through one
 * named pipeline:
 *
 *   time -> run (timeout + exception guard) -> record run -> update counters
 *        -> mute gate -> flap gate -> open/escalate/notify | recover gate -> recover
 *
 * All per-check declarations are read off the persisted HealthCheck row (not a
 * transient Check instance) so they survive queue serialization. The check itself is
 * the registered instance for the row's key, unless the caller hands one in (a run-now
 * of a specific instance) — the registry is never modified to make that happen.
 *
 * @internal the pipeline behind the queued HealthCheckJob and RunHealthCheckNowAction;
 *           hosts (and a custom `alerts.job`) run a scheduled row with
 *           `Health::for($notifiable)->run($healthCheck)`
 */
final readonly class RunHealthCheckAction
{
    public function execute(HealthCheck $healthCheck, ?Check $check = null): CheckResult
    {
        $check = $check === null ? $healthCheck->healthCheck() : $check->withHealthCheck($healthCheck);
        $options = $healthCheck->options();

        [$result, $durationMs] = $this->runCheck($check, $healthCheck, $options);

        $this->recordRun($healthCheck, $result, $durationMs);

        // A skipped result records a run but maintains no counters and triggers no
        // alert side effects.
        if ($result->status === Status::Skipped) {
            return $result;
        }

        $this->updateCounters($healthCheck, $result);

        $muted = $this->isMuted($healthCheck, $check);

        if ($result->isOk) {
            $this->handleRecovery($healthCheck, $options, $muted);

            return $result;
        }

        $this->handleFailure($check, $healthCheck, $options, $result, $muted);

        return $result;
    }

    /**
     * @return array{0: CheckResult, 1: int}
     */
    private function runCheck(Check $check, HealthCheck $healthCheck, MonitorOptions $options): array
    {
        $start = (int) hrtime(true);
        $timeout = $options->timeout();

        try {
            $result = $timeout === null
                ? $check->check()
                : Timeout::run($timeout, $healthCheck->health_check, fn (): CheckResult => $check->check());
        } catch (CheckTimedOut $e) {
            $result = CheckResult::fromException($e, $e->getMessage(), [
                'timed_out_after' => $e->seconds,
            ]);
        } catch (Throwable $e) {
            $result = CheckResult::fromException($e);
        }

        $durationMs = (int) round(((int) hrtime(true) - $start) / 1_000_000);

        return [$result, $durationMs];
    }

    private function recordRun(HealthCheck $healthCheck, CheckResult $result, int $durationMs): void
    {
        if (config('alerts.history.enabled', true) !== true) {
            return;
        }

        HealthCheckRunModel::class()::create([
            'health_check_id' => $healthCheck->getKey(),
            'status' => $result->status,
            'duration_ms' => $durationMs,
            'message' => $result->storedMessage(),
            'meta' => $result->meta,
            'ran_at' => now(),
        ]);
    }

    /**
     * The counters are written RELATIVELY (`failures = failures + 1`), never as a
     * literal computed from the value this worker read. Two runs of the same monitor
     * can overlap — the scheduler's `withoutOverlapping()` guards the command, not
     * the per-monitor jobs it queues — and a lost increment silently defers the alert
     * past its `failAfter` threshold. The row is then re-read, so the gates below see
     * the true count rather than this worker's stale arithmetic.
     */
    private function updateCounters(HealthCheck $healthCheck, CheckResult $result): void
    {
        $result->isOk
            ? $healthCheck->increment('consecutive_successes', 1, ['consecutive_failures' => 0])
            : $healthCheck->increment('consecutive_failures', 1, ['consecutive_successes' => 0]);

        $healthCheck->refresh();
    }

    private function handleFailure(
        Check $check,
        HealthCheck $healthCheck,
        MonitorOptions $options,
        CheckResult $result,
        bool $muted,
    ): void {
        $alert = $this->retrieveOpenAlert($healthCheck);

        if ($alert !== null) {
            // An open alert describes the incident as it is NOW: a warning that turned
            // into a failure (or back) must not keep reporting the first result, and a
            // mute that ended must stop flagging it.
            $this->follow($alert, $result, $muted);
        }

        // Flap gate: only open/notify once the failure has persisted long enough.
        if ($healthCheck->consecutive_failures < $options->failAfter()) {
            return;
        }

        $alert ??= $this->createAlert($healthCheck, $result, $muted);

        if ($muted) {
            return;
        }

        HealthCheckFailed::dispatch($alert);

        $this->escalate($check, $healthCheck, $options, $alert);
    }

    private function escalate(Check $check, HealthCheck $healthCheck, MonitorOptions $options, Alert $alert): void
    {
        $fromLevel = $alert->escalation_level;
        $toLevel = $options->levelForFailures($healthCheck->consecutive_failures);

        if ($options->escalation() === []) {
            // No policy: preserve current behaviour — notify the default group.
            $this->notifyDefaultGroup($check, $healthCheck, $options, level: 0);

            return;
        }

        if ($toLevel <= $fromLevel) {
            return;
        }

        // Compare-and-swap, not a blind write: two overlapping runs can compute the
        // same transition from the level they both read, and paging a tier twice is
        // exactly what an escalation policy exists to avoid. The row that loses the
        // race sees zero affected rows and stops.
        $taken = AlertModel::query()
            ->whereKey($alert->getKey())
            ->where('escalation_level', $fromLevel)
            ->update(['escalation_level' => $toLevel]);

        if ($taken === 0) {
            return;
        }

        $alert->setAttribute('escalation_level', $toLevel)->syncChanges();

        HealthCheckEscalated::dispatch($alert, $fromLevel, $toLevel);

        $channels = $options->channelsForLevel($toLevel, $this->defaultChannels());
        $check->via($channels);

        foreach ($options->groupsBetween($fromLevel, $toLevel) as $group) {
            foreach ($healthCheck->notifiablesForGroup($group) as $notifiable) {
                $check->notify($notifiable);
            }
        }
    }

    private function notifyDefaultGroup(Check $check, HealthCheck $healthCheck, MonitorOptions $options, int $level): void
    {
        $check->via($options->channelsForLevel($level, $this->defaultChannels()));

        $healthCheck->forEachNotifiable(
            fn (object $notifiable) => $check->notify($notifiable),
        );
    }

    private function handleRecovery(HealthCheck $healthCheck, MonitorOptions $options, bool $muted): void
    {
        $alert = $this->retrieveOpenAlert($healthCheck);

        if ($alert === null) {
            return;
        }

        // Recovery gate: require confirmed consecutive successes before closing.
        if ($healthCheck->consecutive_successes < $options->recoverAfter()) {
            return;
        }

        // A conditional close, not a write to the alert this run read: two overlapping
        // healthy runs both see it open, and only the one whose UPDATE still finds an
        // open row announces the recovery. It closes every open alert of the monitor,
        // so an orphan left by an older bug or a manual insert cannot keep it red.
        $closed = $this->openAlerts($healthCheck)->update([
            'recovered_at' => now(),
            'escalation_level' => 0,
            'open_slot' => null,
        ]);

        if ($closed === 0) {
            return;
        }

        $healthCheck->forceFill(['consecutive_successes' => 0])->save();

        if (! $muted) {
            HealthCheckRecovered::dispatch($alert->refresh());
        }
    }

    private function isMuted(HealthCheck $healthCheck, Check $check): bool
    {
        if (config('alerts.silence', true) !== true) {
            return false;
        }

        $keys = array_values(array_unique([
            $healthCheck->health_check,
            ...$healthCheck->effectiveTags(),
            ...$check->tags(),
            AlertSilence::GLOBAL_KEY,
        ]));

        return AlertSilenceModel::query()
            ->matching($keys, $healthCheck->notifiable)
            ->active(now())
            ->exists();
    }

    private function follow(Alert $alert, CheckResult $result, bool $muted): void
    {
        $alert->update([
            'status' => $result->status,
            'message' => $result->storedMessage(),
            'meta' => $this->alertMeta($result, $muted),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function alertMeta(CheckResult $result, bool $muted): array
    {
        $meta = $result->meta;

        if ($muted) {
            $meta[MonitorOptions::MUTED] = true;
        }

        return $meta;
    }

    /**
     * Open the monitor's alert. The unique (health_check_id, open_slot) index is what
     * makes this safe when two failing runs overlap: the run whose insert loses adopts
     * the alert the other one opened. A slot still held by an alert that left the
     * pipeline's hands — soft-deleted, or resolved by hand — is released and retried.
     */
    private function createAlert(HealthCheck $healthCheck, CheckResult $result, bool $muted): Alert
    {
        $notifiable = $this->notifiable($healthCheck);

        $attributes = [
            'notifiable_type' => $notifiable->getMorphClass(),
            'notifiable_id' => $notifiable->getKey(),
            'health_check_id' => $healthCheck->getKey(),
            'status' => $result->status,
            'escalation_level' => 0,
            'triggered_at' => now(),
            'message' => $result->storedMessage(),
            'meta' => $this->alertMeta($result, $muted),
            'open_slot' => Alert::OPEN_SLOT,
        ];

        try {
            return $this->insertAlert($attributes);
        } catch (UniqueConstraintViolationException) {
            // Another run of this monitor opened it after this run looked.
        }

        $holder = $this->slotHolder($healthCheck)->first();

        if ($holder !== null && ! $holder->trashed() && $holder->recovered_at === null) {
            $this->follow($holder, $result, $muted);

            return $holder;
        }

        $this->slotHolder($healthCheck)->update(['open_slot' => null]);

        return $this->insertAlert($attributes);
    }

    /**
     * In its own transaction (a savepoint when the caller already holds one), so a lost
     * race on postgres rolls back this insert alone, not the caller's transaction.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function insertAlert(array $attributes): Alert
    {
        $model = AlertModel::class();

        return AlertModel::query()->getConnection()->transaction(
            fn (): Alert => $model::create($attributes),
        );
    }

    /**
     * @return Builder<Alert>
     */
    private function slotHolder(HealthCheck $healthCheck): Builder
    {
        return AlertModel::query()
            ->withTrashed()
            ->where('health_check_id', $healthCheck->getKey())
            ->where('open_slot', Alert::OPEN_SLOT);
    }

    /**
     * @return Builder<Alert>
     */
    private function openAlerts(HealthCheck $healthCheck): Builder
    {
        $notifiable = $this->notifiable($healthCheck);

        return AlertModel::query()
            ->where('notifiable_type', $notifiable->getMorphClass())
            ->where('notifiable_id', $notifiable->getKey())
            ->where('health_check_id', $healthCheck->getKey())
            ->whereNull('recovered_at');
    }

    /**
     * The newest open alert. Ordered by id, not `triggered_at`: that column has
     * one-second precision, and a fail/ok/fail inside one second used to pick an
     * arbitrary alert among the ties — reopening beside one that never closed.
     */
    private function retrieveOpenAlert(HealthCheck $healthCheck): ?Alert
    {
        return $this->openAlerts($healthCheck)->latest('id')->first();
    }

    private function notifiable(HealthCheck $healthCheck): Model
    {
        $notifiable = $healthCheck->notifiable;

        if ($notifiable === null) {
            throw InvalidNotifiableForHealthCheck::doesntImplementInterface(null);
        }

        return $notifiable;
    }

    /**
     * @return list<string>
     */
    private function defaultChannels(): array
    {
        return ['mail', 'database'];
    }
}
