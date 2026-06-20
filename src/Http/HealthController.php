<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RoundlyConsulting\Alerts\Actions\BuildHealthReportAction;

final class HealthController
{
    public function __invoke(Request $request, BuildHealthReportAction $action): JsonResponse
    {
        $tag = $request->query('tag');
        $tags = is_string($tag) && $tag !== '' ? [$tag] : null;

        $report = $action->execute(tags: $tags);

        return new JsonResponse(
            $report->toArray(),
            $report->isHealthy() ? 200 : 503,
        );
    }
}
