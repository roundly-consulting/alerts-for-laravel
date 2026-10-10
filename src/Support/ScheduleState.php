<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Support;

use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\DataTransferObjects\ReconfigureHealthCheckData;
use RoundlyConsulting\Alerts\DataTransferObjects\ScheduleHealthCheckData;
use RoundlyConsulting\Alerts\Exceptions\InvalidHealthCheck;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\HealthManager;

/**
 * The columns a schedule is made of, and the one rule for whether a schedule changed: shared
 * by `schedule()`, `reconfigure()` and `Health::fake()`, so the real manager and the fake
 * can never disagree on it.
 *
 * @internal
 */
final class ScheduleState
{
    /**
     * The flap counters a change resets.
     *
     * @var array<string, int>
     */
    public const array RESET_COUNTERS = ['consecutive_failures' => 0, 'consecutive_successes' => 0];

    /**
     * The settings `schedule()` writes, rebuilt from the DTO (replace semantics).
     *
     * @return array<string, mixed>
     */
    public static function fromData(ScheduleHealthCheckData $data, string $frequency): array
    {
        return [
            'frequency' => $frequency,
            'max_attempts' => $data->maxAttempts,
            'decay_minutes' => $data->decayMinutes,
            'tags' => $data->tags === [] ? null : $data->tags,
            'meta' => $data->metaWithOptions(),
        ];
    }

    /**
     * The settings `reconfigure()` writes: the row's own, with the changes merged in.
     *
     * @return array<string, mixed>
     */
    public static function merged(HealthCheck $row, ReconfigureHealthCheckData $changes): array
    {
        $meta = $row->meta ?? [];

        foreach ($changes->meta as $key => $value) {
            if ($value === null) {
                unset($meta[$key]);
            } else {
                $meta[$key] = $value;
            }
        }

        foreach ([MonitorOptions::FAIL_AFTER => $changes->failAfter, MonitorOptions::RECOVER_AFTER => $changes->recoverAfter] as $key => $value) {
            if ($value === null) {
                continue;
            }

            // 1 is the default, so the setting is removed, as `schedule()` never writes it.
            if ($value > 1) {
                $meta[$key] = $value;
            } else {
                unset($meta[$key]);
            }
        }

        return [
            'frequency' => $changes->cronFrequency() ?? $row->frequency,
            'max_attempts' => $changes->maxAttempts ?? $row->max_attempts,
            'decay_minutes' => $changes->decayMinutes ?? $row->decay_minutes,
            'tags' => match (true) {
                $changes->tags === null => $row->tags,
                $changes->tags === [] => null,
                default => array_values(array_unique($changes->tags)),
            },
            'meta' => $meta,
        ];
    }

    /**
     * Only the settings that differ from the row. Tags compare as sets; meta compares after a
     * recursive key sort, because PostgreSQL `jsonb` and MySQL `JSON` reorder object keys and
     * a plain comparison would "change" every schedule on every deploy.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function changes(HealthCheck $row, array $settings): array
    {
        $changes = [];

        foreach ($settings as $column => $value) {
            $current = $row->getAttribute($column);

            $same = match ($column) {
                'tags' => self::tagSet($current) === self::tagSet($value),
                'meta' => self::sorted($current ?? []) === self::sorted($value ?? []),
                default => $current === $value,
            };

            if (! $same) {
                $changes[$column] = $value;
            }
        }

        return $changes;
    }

    /**
     * The meta keys a schedule of the check is unique by, or null for none — read off the
     * instance registered for it (or, for an unregistered Check class, a fresh one).
     *
     * @return list<string>|null
     */
    public static function uniqueBy(HealthManager $health, string $check, string $key): ?array
    {
        $instance = $health->find($key)
            ?? (is_subclass_of($check, Check::class) ? $health->resolveCheck($check) : null);

        return $instance?->uniqueBy();
    }

    /**
     * The identity of a live schedule of a `uniqueBy()` check.
     *
     * @param  list<string>  $uniqueBy
     * @param  array<array-key, mixed>|null  $meta
     *
     * @throws InvalidHealthCheck for a discriminator that is not a scalar or null
     */
    public static function identity(string $notifiableType, mixed $notifiableId, string $key, array $uniqueBy, ?array $meta): string
    {
        return Identity::schedule($notifiableType, $notifiableId, $key, Identity::discriminators($key, $uniqueBy, $meta));
    }

    /**
     * @return list<string>
     */
    private static function tagSet(mixed $tags): array
    {
        $set = is_array($tags) ? array_values(array_unique(array_map('strval', $tags))) : [];
        sort($set);

        return $set;
    }

    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        ksort($value);

        return array_map(self::sorted(...), $value);
    }
}
