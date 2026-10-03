<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Support;

use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Strict reads of the host's non-boolean alerts settings. A key that is not set (absent,
 * null or blank — a host's `KEY=`) takes the default; any other invalid value throws
 * {@see InvalidConfigurationException} naming the key — a typo never silently becomes a
 * different setting.
 *
 * @internal
 */
final class AlertsConfig
{
    /**
     * The scheduler frequencies `schedule.frequency` may name: Laravel's zero-argument
     * frequency methods from every minute up. Sub-minute frequencies are left out on
     * purpose — a due check stays due for its whole minute, so a sub-minute runner would
     * queue the same check several times.
     *
     * @var non-empty-list<string>
     */
    public const array SCHEDULE_FREQUENCIES = [
        'everyMinute',
        'everyTwoMinutes',
        'everyThreeMinutes',
        'everyFourMinutes',
        'everyFiveMinutes',
        'everyTenMinutes',
        'everyFifteenMinutes',
        'everyThirtyMinutes',
        'hourly',
        'everyOddHour',
        'everyTwoHours',
        'everyThreeHours',
        'everyFourHours',
        'everySixHours',
        'daily',
        'twiceDaily',
        'weekly',
        'monthly',
        'quarterly',
        'yearly',
    ];

    /**
     * Days of run history `alerts:prune-runs` keeps. At least one: a retention of 0 would
     * delete the whole history on the next daily prune.
     */
    public static function retentionDays(): int
    {
        return Config::integer('alerts.history.retention_days', 30, min: 1);
    }

    /**
     * The scheduler method the perform command is registered with.
     */
    public static function scheduleFrequency(): string
    {
        return Config::oneOf('alerts.schedule.frequency', self::SCHEDULE_FREQUENCIES, 'everyMinute');
    }

    /**
     * The health endpoint URI. A blank one is not set and takes `health`, so it never
     * serves the endpoint at the site root.
     */
    public static function routeUri(): string
    {
        return self::string('alerts.route.uri', 'health');
    }

    public static function routeName(): string
    {
        return self::string('alerts.route.name', 'alerts.health');
    }

    private static function string(string $key, string $default): string
    {
        $value = config($key);

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return $default;
        }

        if (! is_string($value)) {
            throw InvalidConfigurationException::notAString($key, $value);
        }

        return $value;
    }
}
