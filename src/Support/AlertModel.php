<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Support;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;

/**
 * Resolves the Eloquent model recording alerts from `alerts.alert`.
 *
 * The toolkit ModelResolver validates that the configured value is a real Eloquent
 * model; it cannot know it is *ours*, so anything that is not an Alert (and so
 * cannot answer the `open()` / `recovered()` scopes the package queries through)
 * falls back to the packaged model.
 */
final class AlertModel
{
    /** @return class-string<Alert> */
    public static function class(): string
    {
        $model = ModelResolver::for('alerts.alert', Alert::class);

        return is_a($model, Alert::class, true) ? $model : Alert::class;
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
