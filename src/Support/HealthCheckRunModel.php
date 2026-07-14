<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Support;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\Alerts\HealthCheckRun;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;

/**
 * Resolves the Eloquent model recording run history from `alerts.history.model`.
 *
 * The toolkit ModelResolver validates that the configured value is a real Eloquent
 * model; it cannot know it is *ours*, so anything that is not a HealthCheckRun (and
 * so cannot answer the status/latency reads uptime and p95 are built from) falls
 * back to the packaged model.
 */
final class HealthCheckRunModel
{
    /** @return class-string<HealthCheckRun> */
    public static function class(): string
    {
        $model = ModelResolver::for('alerts.history.model', HealthCheckRun::class);

        return is_a($model, HealthCheckRun::class, true) ? $model : HealthCheckRun::class;
    }

    public static function new(): HealthCheckRun
    {
        $model = self::class();

        return new $model;
    }

    /** @return Builder<HealthCheckRun> */
    public static function query(): Builder
    {
        return self::class()::query();
    }
}
