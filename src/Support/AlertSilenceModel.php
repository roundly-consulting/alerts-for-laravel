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
 * The toolkit ModelResolver validates that the configured value is a real Eloquent
 * model; it cannot know it is *ours*, so anything that is not an AlertSilence (and
 * so cannot answer the `matching()` / `active()` scopes the mute gate queries
 * through) falls back to the packaged model.
 */
final class AlertSilenceModel
{
    /** @return class-string<AlertSilence> */
    public static function class(): string
    {
        $model = ModelResolver::for('alerts.silence-model', AlertSilence::class);

        return is_a($model, AlertSilence::class, true) ? $model : AlertSilence::class;
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
