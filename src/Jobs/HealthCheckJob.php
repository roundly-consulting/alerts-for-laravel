<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\Alerts\CheckResult;
use RoundlyConsulting\Alerts\Events\HealthCheckFailed;
use RoundlyConsulting\Alerts\Events\HealthCheckRecovered;
use RoundlyConsulting\Alerts\Exceptions\InvalidNotifiableForHealthCheck;
use RoundlyConsulting\Alerts\HealthCheck;

final class HealthCheckJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public HealthCheck $healthCheck,
    ) {}

    public function handle(): void
    {
        $check = $this->healthCheck->healthCheck();
        $result = $check->check();

        $alert = $this->retrieveLatestAlert();

        if ($result->isOk) {
            if ($alert !== null && $alert->recovered_at === null) {
                $alert->update(['recovered_at' => now()]);

                HealthCheckRecovered::dispatch($alert);
            }

            return;
        }

        if ($alert === null || $alert->recovered_at !== null) {
            $alert = $this->createAlert($result);
        }

        HealthCheckFailed::dispatch($alert);

        $this->healthCheck->forEachNotifiable(
            fn (object $notifiable) => $check->notify($notifiable),
        );
    }

    protected function createAlert(CheckResult $result): Alert
    {
        $notifiable = $this->notifiable();

        return $this->alertModel()::create([
            'notifiable_type' => $notifiable->getMorphClass(),
            'notifiable_id' => $notifiable->getKey(),
            'health_check_id' => $this->healthCheck->getKey(),
            'triggered_at' => now(),
            'message' => $result->message,
            'meta' => $result->meta,
        ]);
    }

    protected function retrieveLatestAlert(): ?Alert
    {
        $notifiable = $this->notifiable();

        return $this->alertModel()::query()
            ->where('notifiable_type', $notifiable->getMorphClass())
            ->where('notifiable_id', $notifiable->getKey())
            ->where('health_check_id', $this->healthCheck->getKey())
            ->latest('triggered_at')
            ->first();
    }

    protected function notifiable(): Model
    {
        $notifiable = $this->healthCheck->notifiable;

        if ($notifiable === null) {
            throw InvalidNotifiableForHealthCheck::doesntImplementInterface(null);
        }

        return $notifiable;
    }

    /**
     * @return class-string<Alert>
     */
    protected function alertModel(): string
    {
        /** @var class-string<Alert> $model */
        $model = config('alerts.alert', Alert::class);

        return $model;
    }
}
