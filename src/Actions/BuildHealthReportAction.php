<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Actions;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\HealthManager;
use RoundlyConsulting\Alerts\Status\CheckStatus;
use RoundlyConsulting\Alerts\Status\HealthReport;
use RoundlyConsulting\Alerts\Support\AlertModel;
use RoundlyConsulting\Alerts\Support\HealthCheckModel;
use RoundlyConsulting\Alerts\Support\HealthCheckRunModel;
use RoundlyConsulting\Alerts\Support\SilenceSnapshot;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Builds a current health report from the HealthCheck rows — scheduled monitors and the
 * on-demand rows `Health::for($owner)->run()` creates — and their latest open alerts,
 * optionally scoped to a single notifiable and/or tags. A failed on-demand run stays in
 * the report until a passing re-run of the check closes its alert.
 *
 * A fixed number of queries whatever the number of rows or the retained history: the rows,
 * their open alerts, one run aggregate (uptime), one window-function read of the nearest-rank
 * p95 (MySQL 8.0+, PostgreSQL, SQLite 3.25+) and one silence snapshot — row ids batched by
 * {@see self::BATCH} into each `whereIn`.
 */
final readonly class BuildHealthReportAction
{
    /**
     * Row ids per `whereIn`, well under every engine's bound-parameter limit.
     */
    public const int BATCH = 1000;

    public function __construct(
        private HealthManager $health,
    ) {}

    /**
     * @param  list<string>|null  $tags
     * @param  CarbonInterface|null  $since  only runs at or after this moment count towards uptime and p95
     * @param  bool  $scheduledOnly  leave out the on-demand rows of run-now (`frequency` null)
     */
    public function execute(
        ?Model $notifiable = null,
        ?array $tags = null,
        ?CarbonInterface $since = null,
        bool $scheduledOnly = false,
    ): HealthReport {
        $rows = $this->healthCheckQuery($notifiable, $tags, $scheduledOnly)->oldest('id')->get();

        if ($rows->isEmpty()) {
            return new HealthReport;
        }

        $ids = array_values(array_map(fn (HealthCheck $row): int|string => $row->getKey(), $rows->all()));

        $alerts = $this->openAlerts($rows, $ids);
        [$uptime, $p95] = Config::boolean('alerts.history.enabled', true)
            ? [$this->uptime($ids, $since), $this->p95($ids, $since)]
            : [[], []];
        $silences = $this->health->silenceSnapshotFor(now());

        return new HealthReport(array_values(array_map(
            fn (HealthCheck $row): CheckStatus => $this->status($row, $alerts, $uptime, $p95, $silences),
            $rows->all(),
        )));
    }

    /**
     * @param  array<int|string, Alert>  $alerts
     * @param  array<int|string, float>  $uptime
     * @param  array<int|string, int>  $p95
     */
    private function status(HealthCheck $row, array $alerts, array $uptime, array $p95, SilenceSnapshot $silences): CheckStatus
    {
        $id = $row->getKey();
        $alert = $alerts[$id] ?? null;

        return new CheckStatus(
            key: $row->health_check,
            name: $this->name($row),
            status: $alert === null ? Status::Ok : $alert->status,
            lastAlertAt: $alert?->triggered_at,
            message: $alert?->message,
            tags: $row->effectiveTags(),
            uptime: $uptime[$id] ?? null,
            p95LatencyMs: $p95[$id] ?? null,
            muted: $silences->covers($row),
            meta: $alert?->meta,
        );
    }

    /**
     * @param  list<string>|null  $tags
     * @return Builder<HealthCheck>
     */
    private function healthCheckQuery(?Model $notifiable, ?array $tags, bool $scheduledOnly): Builder
    {
        $query = HealthCheckModel::query();

        if ($notifiable !== null) {
            $query->where('notifiable_type', $notifiable->getMorphClass())
                ->where('notifiable_id', $notifiable->getKey());
        }

        if ($scheduledOnly) {
            $query->whereNotNull('frequency');
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

    /**
     * The newest open alert of each row — ordered by id, not the one-second `triggered_at` —
     * raised for the row's own notifiable.
     *
     * @param  Collection<int, HealthCheck>  $rows
     * @param  list<int|string>  $ids
     * @return array<int|string, Alert>
     */
    private function openAlerts(Collection $rows, array $ids): array
    {
        $rowsById = $rows->keyBy(fn (HealthCheck $row): int|string => $row->getKey());
        $alerts = [];

        foreach (array_chunk($ids, self::BATCH) as $batch) {
            $open = AlertModel::query()
                ->whereIn('health_check_id', $batch)
                ->open()
                ->orderByDesc('id')
                ->get();

            foreach ($open as $alert) {
                $row = $rowsById->get($alert->health_check_id);

                if (isset($alerts[$alert->health_check_id]) || $row === null || ! $this->raisedFor($alert, $row)) {
                    continue;
                }

                $alerts[$alert->health_check_id] = $alert;
            }
        }

        return $alerts;
    }

    private function raisedFor(Alert $alert, HealthCheck $row): bool
    {
        return $alert->notifiable_type === $row->notifiable_type
            && (string) $alert->notifiable_id === (string) $row->notifiable_id;
    }

    /**
     * Percentage of runs whose status is not alertable (ok and skipped — pinned by a test
     * against `Status::isAlertable()`), per row with runs in the window: the figure
     * `HealthCheck::uptimePercentage()` computes for one row. `sum(case …)` rather than
     * `count(*) filter (…)`, which MySQL lacks.
     *
     * @param  list<int|string>  $ids
     * @return array<int|string, float>
     */
    private function uptime(array $ids, ?CarbonInterface $since): array
    {
        $uptime = [];

        foreach (array_chunk($ids, self::BATCH) as $batch) {
            $aggregates = $this->runs($batch, $since)
                ->select('health_check_id')
                ->selectRaw('count(*) as run_total')
                ->selectRaw('sum(case when status in (?, ?) then 1 else 0 end) as run_healthy', [Status::Ok->value, Status::Skipped->value])
                ->groupBy('health_check_id')
                ->get();

            foreach ($aggregates as $aggregate) {
                $uptime[$aggregate->health_check_id] = round(((int) $aggregate->run_healthy / (int) $aggregate->run_total) * 100, 2);
            }
        }

        return $uptime;
    }

    /**
     * The nearest-rank 95th-percentile duration per row with runs in the window — the value
     * `Percentile::nearestRank()` and `HealthCheck::p95LatencyMs()` define: the row at rank
     * ceil(0.95 × n) in duration order, picked with integers only (`rank × 100 ≥ 95 × n` and
     * `(rank − 1) × 100 < 95 × n`), so no engine's `ceil()` or decimal division is involved.
     *
     * @param  list<int|string>  $ids
     * @return array<int|string, int>
     */
    private function p95(array $ids, ?CarbonInterface $since): array
    {
        $p95 = [];

        foreach (array_chunk($ids, self::BATCH) as $batch) {
            $ranked = $this->runs($batch, $since)
                ->select(['health_check_id', 'duration_ms'])
                ->selectRaw('row_number() over (partition by health_check_id order by duration_ms) as run_rank')
                ->selectRaw('count(*) over (partition by health_check_id) as run_total');

            $picked = $ranked->newQuery()
                ->fromSub($ranked, 'ranked_runs')
                ->select(['health_check_id', 'duration_ms'])
                ->whereRaw('run_rank * 100 >= 95 * run_total')
                ->whereRaw('(run_rank - 1) * 100 < 95 * run_total')
                ->get();

            foreach ($picked as $run) {
                $p95[$run->health_check_id] = (int) $run->duration_ms;
            }
        }

        return $p95;
    }

    /**
     * @param  list<int|string>  $ids
     */
    private function runs(array $ids, ?CarbonInterface $since): QueryBuilder
    {
        $query = HealthCheckRunModel::query()->toBase()->whereIn('health_check_id', $ids);

        if ($since !== null) {
            $query->where('ran_at', '>=', $since);
        }

        return $query;
    }

    private function name(HealthCheck $healthCheck): string
    {
        $check = $this->health->find($healthCheck->health_check);

        return $check?->name() ?? $healthCheck->health_check;
    }
}
