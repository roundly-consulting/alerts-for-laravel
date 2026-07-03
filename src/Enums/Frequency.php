<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Enums;

use RoundlyConsulting\Enums\Helpers;

/**
 * Named scheduling presets mapped to cron expressions, reused by the fluent
 * scheduled-check builder and the schedule auto-registration.
 */
enum Frequency: string
{
    use Helpers;

    case EveryMinute = '* * * * *';
    case EveryFiveMinutes = '*/5 * * * *';
    case EveryTenMinutes = '*/10 * * * *';
    case EveryFifteenMinutes = '*/15 * * * *';
    case EveryThirtyMinutes = '*/30 * * * *';
    case Hourly = '@hourly';
    case Daily = '@daily';
    case Weekly = '@weekly';
    case Monthly = '@monthly';
    case Yearly = '@yearly';

    /**
     * Resolve a preset name (e.g. `hourly`, `everyFiveMinutes`) or a raw cron
     * string to a cron expression.
     */
    public static function toCron(string $value): string
    {
        $needle = mb_strtolower($value);

        foreach (self::cases() as $case) {
            if (mb_strtolower($case->name) === $needle || $case->value === $value) {
                return $case->value;
            }
        }

        return $value;
    }
}
