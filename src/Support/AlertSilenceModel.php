<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Support;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\Alerts\AlertSilence;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;

/**
 * Resolves the Eloquent model persisting maintenance windows from
 * `alerts.silence-model`.
 *
 * Absent config resolves the packaged model; anything else must be that model or a subclass of
 * it, or the toolkit's ModelResolver throws InvalidConfigurationException naming the key — a
 * foreign class is never silently replaced.
 */
final class AlertSilenceModel
{
    /** @return class-string<AlertSilence> */
    public static function class(): string
    {
        return ModelResolver::for('alerts.silence-model', AlertSilence::class);
    }

    public static function new(): AlertSilence
    {
        $model = self::class();

        return new $model;
    }

    /** @return Builder<AlertSilence> */
    public static function query(): Builder
    {
        return self::class()::query();
    }
}
