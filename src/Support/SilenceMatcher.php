<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\Alerts\AlertSilence;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\HealthCheck;

/**
 * The one muting rule. A silence covers a row when its key is the row's check key, one of the
 * row's effective tags (plus a run-now instance's own tags) or `*`, and its scope is global or
 * the row's own notifiable. The runner's mute gate, `Health::silences()->snapshot()` and
 * `Health::fake()` all decide through here, so they cannot drift apart.
 *
 * @internal
 */
final class SilenceMatcher
{
    /**
     * The silence keys that can cover the row.
     *
     * @return list<string>
     */
    public static function keys(HealthCheck $row, ?Check $check = null): array
    {
        return array_values(array_unique([
            $row->health_check,
            ...$row->effectiveTags(),
            ...($check?->tags() ?? []),
            AlertSilence::GLOBAL_KEY,
        ]));
    }

    /**
     * Whether a silence's scope reaches the row: a global one, or one scoped to the row's own
     * notifiable (the id compared as text).
     */
    public static function reaches(AlertSilence $silence, HealthCheck $row): bool
    {
        if ($silence->notifiable_id === null) {
            return true;
        }

        return $silence->notifiable_type === $row->notifiable_type
            && (string) $silence->notifiable_id === (string) $row->notifiable_id;
    }

    /**
     * Whether the silence is in force at the moment: started (or immediate) and not yet ended.
     */
    public static function isActive(AlertSilence $silence, CarbonInterface $at): bool
    {
        return ($silence->starts_at === null || $silence->starts_at->lessThanOrEqualTo($at))
            && ($silence->ends_at === null || $silence->ends_at->greaterThanOrEqualTo($at));
    }

    /**
     * The rule in memory, for a silence already known to be in force.
     */
    public static function covers(AlertSilence $silence, HealthCheck $row, ?Check $check = null): bool
    {
        return in_array($silence->key, self::keys($row, $check), true) && self::reaches($silence, $row);
    }

    /**
     * The rule in the database: the silences in force at the moment that cover the row.
     *
     * @return Builder<AlertSilence>
     */
    public static function query(HealthCheck $row, ?Check $check, CarbonInterface $at): Builder
    {
        return AlertSilenceModel::query()
            ->whereIn('key', self::keys($row, $check))
            ->where(function (Builder $scope) use ($row): void {
                $scope->whereNull('notifiable_id')
                    ->orWhere(function (Builder $own) use ($row): void {
                        $own->where('notifiable_type', $row->notifiable_type)
                            ->where('notifiable_id', $row->notifiable_id);
                    });
            })
            ->active($at);
    }
}
