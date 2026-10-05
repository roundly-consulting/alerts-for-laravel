<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RoundlyConsulting\Alerts\HealthManager;
use RoundlyConsulting\Alerts\Status\CheckStatus;
use RoundlyConsulting\PackageToolkit\Support\Config;

final class HealthController
{
    public function __invoke(Request $request, HealthManager $health): JsonResponse
    {
        $tag = $request->query('tag');
        $tags = is_string($tag) && $tag !== '' ? [$tag] : null;

        $report = $health->report($tags);

        return new JsonResponse(
            [
                'status' => $report->overall()->value,
                'checks' => array_map($this->render(...), $report->checks()),
            ],
            $report->isHealthy() ? 200 : 503,
        );
    }

    /**
     * The endpoint is unauthenticated unless the host adds middleware, and a stored alert
     * message can carry raw exception text (hosts, databases, credentials in a DSN), so by
     * default a check renders only what a status page needs. `alerts.route.details` opts
     * into the full CheckStatus — message, tags, last alert time and the muted flag.
     *
     * @return array<string, mixed>
     */
    private function render(CheckStatus $check): array
    {
        if (Config::boolean('alerts.route.details', false)) {
            return $check->toArray();
        }

        return [
            'key' => $check->key,
            'name' => $check->name,
            'status' => $check->status->value,
            'uptime' => $check->uptime,
            'p95_latency_ms' => $check->p95LatencyMs,
        ];
    }
}
