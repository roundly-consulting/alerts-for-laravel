<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Support;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;

/**
 * Resolves the Eloquent model backing a scheduled check from `alerts.health-check`.
 *
 * The toolkit ModelResolver validates that the configured value is a real Eloquent
 * model; it cannot know it is *ours*, so anything that is not a HealthCheck (and so
 * cannot answer `isDue()`, `options()` or `effectiveTags()`) falls back to the
 * packaged model.
 */
final class HealthCheckModel
{
    /** @return class-string<HealthCheck> */
    public static function class(): string
    {
        $model = ModelResolver::for('alerts.health-check', HealthCheck::class);

        return is_a($model, HealthCheck::class, true) ? $model : HealthCheck::class;
    }

    public static function new(): HealthCheck
    {
        $model = self::class();

        return new $model;
    }

    /** @return Builder<HealthCheck> */
    public static function query(): Builder
    {
        return self::class()::query();
    }
}
