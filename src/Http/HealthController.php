<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RoundlyConsulting\Alerts\HealthManager;

final class HealthController
{
    public function __invoke(Request $request, HealthManager $health): JsonResponse
    {
        $tag = $request->query('tag');
        $tags = is_string($tag) && $tag !== '' ? [$tag] : null;

        $report = $health->report($tags);

        return new JsonResponse(
            $report->toArray(),
            $report->isHealthy() ? 200 : 503,
        );
    }
}
