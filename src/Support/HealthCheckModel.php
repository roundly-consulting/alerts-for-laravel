<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Support;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;

/**
 * Resolves the Eloquent model backing a scheduled check from `alerts.health-check`.
 *
 * Absent config resolves the packaged model; anything else must be that model or a subclass of
 * it, or the toolkit's ModelResolver throws InvalidConfigurationException naming the key — a
 * foreign class is never silently replaced.
 */
final class HealthCheckModel
{
    /** @return class-string<HealthCheck> */
    public static function class(): string
    {
        return ModelResolver::for('alerts.health-check', HealthCheck::class);
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
