<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Support;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;

/**
 * Resolves the Eloquent model recording alerts from `alerts.alert`.
 *
 * Absent config resolves the packaged model; anything else must be that model or a subclass of
 * it, or the toolkit's ModelResolver throws InvalidConfigurationException naming the key — a
 * foreign class is never silently replaced.
 */
final class AlertModel
{
    /** @return class-string<Alert> */
    public static function class(): string
    {
        return ModelResolver::for('alerts.alert', Alert::class);
    }

    public static function new(): Alert
    {
        $model = self::class();

        return new $model;
    }

    /** @return Builder<Alert> */
    public static function query(): Builder
    {
        return self::class()::query();
    }
}
