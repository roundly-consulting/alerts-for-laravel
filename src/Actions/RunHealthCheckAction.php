<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Alerts\Alert;
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
 * transient Check instance) so they survive queue serialization. Shared by the
 * queued job and the synchronous Health::run() helper.
 */
final class RunHealthCheckAction
{
    public function execute(HealthCheck $healthCheck): CheckResult
    {
        $check = $healthCheck->healthCheck();
        $options = $healthCheck->options();

        [$result, $durationMs] = $this->runCheck($check, $healthCheck, $options);

        $this->recordRun($healthCheck, $result, $durationMs);

        // A skipped result records a run but maintains no counters and triggers no
        // alert side effects.
        if ($result->status === Status::Skipped) {
            return $result;
        }

        $this->updateCounters($healthCheck, $result);

        $muted = $this->isMuted($healthCheck);

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
            'message' => $result->message !== '' ? $result->message : null,
            'meta' => $result->meta,
            'ran_at' => now(),
        ]);
    }

    private function updateCounters(HealthCheck $healthCheck, CheckResult $result): void
    {
        if ($result->isOk) {
            $healthCheck->forceFill([
                'consecutive_successes' => $healthCheck->consecutive_successes + 1,
                'consecutive_failures' => 0,
            ])->save();

            return;
        }

        $healthCheck->forceFill([
            'consecutive_failures' => $healthCheck->consecutive_failures + 1,
            'consecutive_successes' => 0,
        ])->save();
    }

    private function handleFailure(
        Check $check,
        HealthCheck $healthCheck,
        MonitorOptions $options,
        CheckResult $result,
        bool $muted,
    ): void {
        // Flap gate: only open/notify once the failure has persisted long enough.
        if ($healthCheck->consecutive_failures < $options->failAfter()) {
            return;
        }

        $alert = $this->retrieveLatestAlert($healthCheck);

        if ($alert === null || $alert->recovered_at !== null) {
            $alert = $this->createAlert($healthCheck, $result, $muted);
        }

        if ($muted) {
            $this->flagMuted($alert);

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

        $alert->update(['escalation_level' => $toLevel]);

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
        $alert = $this->retrieveLatestAlert($healthCheck);

        if ($alert === null || $alert->recovered_at !== null) {
            return;
        }

        // Recovery gate: require confirmed consecutive successes before closing.
        if ($healthCheck->consecutive_successes < $options->recoverAfter()) {
            return;
        }

        $alert->update([
            'recovered_at' => now(),
            'escalation_level' => 0,
        ]);

        $healthCheck->forceFill(['consecutive_successes' => 0])->save();

        if (! $muted) {
            HealthCheckRecovered::dispatch($alert);
        }
    }

    private function isMuted(HealthCheck $healthCheck): bool
    {
        if (config('alerts.silence', true) !== true) {
            return false;
        }

        $keys = [$healthCheck->health_check, ...$healthCheck->effectiveTags(), '*'];

        return AlertSilenceModel::query()
            ->matching($keys, $healthCheck->notifiable)
            ->active(now())
            ->exists();
    }

    private function flagMuted(Alert $alert): void
    {
        $meta = $alert->meta ?? [];
        $meta[MonitorOptions::MUTED] = true;

        $alert->update(['meta' => $meta]);
    }

    private function createAlert(HealthCheck $healthCheck, CheckResult $result, bool $muted): Alert
    {
        $notifiable = $this->notifiable($healthCheck);

        $meta = $result->meta;

        if ($muted) {
            $meta[MonitorOptions::MUTED] = true;
        }

        return AlertModel::class()::create([
            'notifiable_type' => $notifiable->getMorphClass(),
            'notifiable_id' => $notifiable->getKey(),
            'health_check_id' => $healthCheck->getKey(),
            'status' => $result->status,
            'escalation_level' => 0,
            'triggered_at' => now(),
            'message' => $result->message,
            'meta' => $meta,
        ]);
    }

    private function retrieveLatestAlert(HealthCheck $healthCheck): ?Alert
    {
        $notifiable = $this->notifiable($healthCheck);

        return AlertModel::query()
            ->where('notifiable_type', $notifiable->getMorphClass())
            ->where('notifiable_id', $notifiable->getKey())
            ->where('health_check_id', $healthCheck->getKey())
            ->latest('triggered_at')
            ->first();
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
