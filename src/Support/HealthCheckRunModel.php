<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Support;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\Alerts\HealthCheckRun;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;

/**
 * Resolves the Eloquent model recording run history from `alerts.history.model`.
 *
 * Absent config resolves the packaged model; anything else must be that model or a subclass of
 * it, or the toolkit's ModelResolver throws InvalidConfigurationException naming the key — a
 * foreign class is never silently replaced.
 */
final class HealthCheckRunModel
{
    /** @return class-string<HealthCheckRun> */
    public static function class(): string
    {
        return ModelResolver::for('alerts.history.model', HealthCheckRun::class);
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
