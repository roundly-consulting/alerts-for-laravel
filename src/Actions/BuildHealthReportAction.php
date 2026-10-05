<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\HealthManager;
use RoundlyConsulting\Alerts\Status\CheckStatus;
use RoundlyConsulting\Alerts\Status\HealthReport;
use RoundlyConsulting\Alerts\Support\AlertModel;
use RoundlyConsulting\Alerts\Support\HealthCheckModel;
use RoundlyConsulting\Alerts\Support\MonitorOptions;

/**
 * Builds a current health report from the HealthCheck rows — scheduled monitors and the
 * on-demand rows `Health::for($owner)->run()` creates — and their latest open alerts,
 * optionally scoped to a single notifiable and/or tags. A failed on-demand run stays in
 * the report until a passing re-run of the check closes its alert.
 */
final readonly class BuildHealthReportAction
{
    public function __construct(
        private HealthManager $health,
    ) {}

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
        $query = HealthCheckModel::query();

        if ($notifiable !== null) {
            $query->where('notifiable_type', $notifiable->getMorphClass())
                ->where('notifiable_id', $notifiable->getKey());
        }

        if ($tags !== null && $tags !== []) {
            // A row matches on its own tags OR its check's tags() — the same effective
            // tags muting and `whereTag()` use. A check-level tag is never stored on the
            // row, so it is matched through the keys of the registered checks carrying it.
            $taggedKeys = $this->health->all()
                ->filter(fn (Check $check): bool => array_intersect($tags, $check->tags()) !== [])
                ->keys()
                ->all();

            $query->where(function (Builder $q) use ($tags, $taggedKeys): void {
                foreach ($tags as $tag) {
                    $q->orWhereJsonContains('tags', $tag);
                }

                if ($taggedKeys !== []) {
                    $q->orWhereIn('health_check', $taggedKeys);
                }
            });
        }

        return $query;
    }

    private function openAlert(HealthCheck $healthCheck): ?Alert
    {
        return AlertModel::query()
            ->where('notifiable_type', $healthCheck->notifiable_type)
            ->where('notifiable_id', $healthCheck->notifiable_id)
            ->where('health_check_id', $healthCheck->getKey())
            ->open()
            ->latest('id')
            ->first();
    }

    private function name(HealthCheck $healthCheck): string
    {
        $check = $this->health->find($healthCheck->health_check);

        return $check?->name() ?? $healthCheck->health_check;
    }
}
