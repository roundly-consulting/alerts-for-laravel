<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\Alerts\CheckResult;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Events\HealthCheckFailed;
use RoundlyConsulting\Alerts\Events\HealthCheckRecovered;
use RoundlyConsulting\Alerts\Exceptions\InvalidNotifiableForHealthCheck;
use RoundlyConsulting\Alerts\HealthCheck;

/**
 * Runs a single scheduled health check and applies its open/recover/notify side
 * effects. Shared by the queued job and the synchronous Health::run() helper so
 * both follow one code path.
 */
final class RunHealthCheckAction
{
    public function execute(HealthCheck $healthCheck): CheckResult
    {
        $check = $healthCheck->healthCheck();
        $result = $check->check();

        // A skipped result records nothing and recovers nothing.
        if ($result->status === Status::Skipped) {
            return $result;
        }

        $alert = $this->retrieveLatestAlert($healthCheck);

        if ($result->isOk) {
            if ($alert !== null && $alert->recovered_at === null) {
                $alert->update(['recovered_at' => now()]);

                HealthCheckRecovered::dispatch($alert);
            }

            return $result;
        }

        if ($alert === null || $alert->recovered_at !== null) {
            $alert = $this->createAlert($healthCheck, $result);
        }

        HealthCheckFailed::dispatch($alert);

        $healthCheck->forEachNotifiable(
            fn (object $notifiable) => $check->notify($notifiable),
        );

        return $result;
    }

    private function createAlert(HealthCheck $healthCheck, CheckResult $result): Alert
    {
        $notifiable = $this->notifiable($healthCheck);

        return $this->alertModel()::create([
            'notifiable_type' => $notifiable->getMorphClass(),
            'notifiable_id' => $notifiable->getKey(),
            'health_check_id' => $healthCheck->getKey(),
            'status' => $result->status,
            'triggered_at' => now(),
            'message' => $result->message,
            'meta' => $result->meta,
        ]);
    }

    private function retrieveLatestAlert(HealthCheck $healthCheck): ?Alert
    {
        $notifiable = $this->notifiable($healthCheck);

        return $this->alertModel()::query()
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
     * @return class-string<Alert>
     */
    private function alertModel(): string
    {
        /** @var class-string<Alert> $model */
        $model = config('alerts.alert', Alert::class);

        return $model;
    }
}
