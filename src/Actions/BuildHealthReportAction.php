<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Status\CheckStatus;
use RoundlyConsulting\Alerts\Status\HealthReport;
use RoundlyConsulting\Alerts\Support\MonitorOptions;

/**
 * Builds a current health report from the scheduled HealthCheck rows and their
 * latest open alerts, optionally scoped to a single notifiable and/or tags.
 */
final class BuildHealthReportAction
{
    /**
     * @param  list<string>|null  $tags
     */
    public function execute(?Model $notifiable = null, ?array $tags = null): HealthReport
    {
        $checks = [];

        $this->healthCheckQuery($notifiable, $tags)->get()->each(
            function (HealthCheck $healthCheck) use (&$checks): void {
                $alert = $this->openAlert($healthCheck);

                $status = $alert === null ? Status::Ok : $alert->status;

                $checks[] = new CheckStatus(
                    key: $healthCheck->health_check,
                    name: $this->name($healthCheck),
                    status: $status,
                    lastAlertAt: $alert?->triggered_at,
                    message: $alert?->message,
                    tags: $healthCheck->effectiveTags(),
                    uptime: $healthCheck->uptimePercentage(),
                    p95LatencyMs: $healthCheck->p95LatencyMs(),
                    muted: (bool) ($alert?->meta[MonitorOptions::MUTED] ?? false),
                );
            },
        );

        return new HealthReport($checks);
    }

    /**
     * @param  list<string>|null  $tags
     * @return Builder<HealthCheck>
     */
    private function healthCheckQuery(?Model $notifiable, ?array $tags): Builder
    {
        /** @var class-string<HealthCheck> $model */
        $model = config('alerts.health-check', HealthCheck::class);

        $query = $model::query();

        if ($notifiable !== null) {
            $query->where('notifiable_type', $notifiable->getMorphClass())
                ->where('notifiable_id', $notifiable->getKey());
        }

        if ($tags !== null && $tags !== []) {
            $query->where(function (Builder $q) use ($tags): void {
                foreach ($tags as $tag) {
                    $q->orWhereJsonContains('tags', $tag);
                }
            });
        }

        return $query;
    }

    private function openAlert(HealthCheck $healthCheck): ?Alert
    {
        /** @var class-string<Alert> $model */
        $model = config('alerts.alert', Alert::class);

        return $model::query()
            ->where('notifiable_type', $healthCheck->notifiable_type)
            ->where('notifiable_id', $healthCheck->notifiable_id)
            ->where('health_check_id', $healthCheck->getKey())
            ->open()
            ->latest('triggered_at')
            ->first();
    }

    private function name(HealthCheck $healthCheck): string
    {
        $check = Health::find($healthCheck->health_check);

        return $check?->name() ?? $healthCheck->health_check;
    }
}
