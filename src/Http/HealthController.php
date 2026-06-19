<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Http;

use Illuminate\Http\JsonResponse;
use RoundlyConsulting\Alerts\Actions\BuildHealthReportAction;

final class HealthController
{
    public function __invoke(BuildHealthReportAction $action): JsonResponse
    {
        $report = $action->execute();

        return new JsonResponse(
            $report->toArray(),
            $report->isHealthy() ? 200 : 503,
        );
    }
}
