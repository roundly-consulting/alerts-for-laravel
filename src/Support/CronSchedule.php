<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use RoundlyConsulting\Alerts\Exceptions\InvalidCronExpression;

/**
 * Minimal native 5-field cron "is due now" evaluator.
 *
 * Supports standard `minute hour day-of-month month day-of-week` expressions with
 * `*`, lists (`1,2,3`), ranges (`1-5`), steps (`* / 5`, `1-30/2`) and the common
 * named aliases (`@hourly`, `@daily`, …). It deliberately covers only what the
 * package's own `Check::frequencies()` and consumers realistically need, keeping the
 * runtime dependency list policy-clean (no third-party cron vendor).
 */
final class CronSchedule
{
    /** @var array<string, string> */
    private const ALIASES = [
        '@yearly' => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly' => '0 0 1 * *',
        '@weekly' => '0 0 * * 0',
        '@daily' => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly' => '0 * * * *',
    ];

    /** @var list<int> */
    private array $minutes;

    /** @var list<int> */
    private array $hours;

    /** @var list<int> */
    private array $daysOfMonth;

    /** @var list<int> */
    private array $months;

    /** @var list<int> */
    private array $daysOfWeek;

    public function __construct(string $expression)
    {
        $normalized = self::ALIASES[mb_strtolower(mb_trim($expression))] ?? $expression;

        $parts = preg_split('/\s+/', mb_trim($normalized)) ?: [];

        if (count($parts) !== 5) {
            throw InvalidCronExpression::malformed($expression);
        }

        $this->minutes = $this->parseField($parts[0], 0, 59, $expression);
        $this->hours = $this->parseField($parts[1], 0, 23, $expression);
        $this->daysOfMonth = $this->parseField($parts[2], 1, 31, $expression);
        $this->months = $this->parseField($parts[3], 1, 12, $expression);
        $this->daysOfWeek = $this->parseField($parts[4], 0, 7, $expression);
    }

    public function isDue(?CarbonInterface $now = null): bool
    {
        $now = $now ?? Carbon::now();

        // Cron treats both 0 and 7 as Sunday; normalise the current weekday set.
        $weekday = (int) $now->dayOfWeek;
        $weekdayMatches = in_array($weekday, $this->daysOfWeek, true)
            || ($weekday === 0 && in_array(7, $this->daysOfWeek, true));

        return in_array((int) $now->minute, $this->minutes, true)
            && in_array((int) $now->hour, $this->hours, true)
            && in_array((int) $now->day, $this->daysOfMonth, true)
            && in_array((int) $now->month, $this->months, true)
            && $weekdayMatches;
    }

    /**
     * @return list<int>
     */
    private function parseField(string $field, int $min, int $max, string $expression): array
    {
        $values = [];

        foreach (explode(',', $field) as $segment) {
            $step = 1;

            if (str_contains($segment, '/')) {
                [$segment, $stepRaw] = explode('/', $segment, 2);

                if (! ctype_digit($stepRaw) || (int) $stepRaw < 1) {
                    throw InvalidCronExpression::malformed($expression);
                }

                $step = (int) $stepRaw;
            }

            [$start, $end] = $this->resolveRange($segment, $min, $max, $expression);

            for ($value = $start; $value <= $end; $value += $step) {
                $values[$value] = $value;
            }
        }

        ksort($values);

        return array_values($values);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function resolveRange(string $segment, int $min, int $max, string $expression): array
    {
        if ($segment === '*' || $segment === '') {
            return [$min, $max];
        }

        if (str_contains($segment, '-')) {
            [$startRaw, $endRaw] = explode('-', $segment, 2);
            $start = $this->parseNumber($startRaw, $min, $max, $expression);
            $end = $this->parseNumber($endRaw, $min, $max, $expression);

            if ($start > $end) {
                throw InvalidCronExpression::malformed($expression);
            }

            return [$start, $end];
        }

        $value = $this->parseNumber($segment, $min, $max, $expression);

        return [$value, $value];
    }

    private function parseNumber(string $raw, int $min, int $max, string $expression): int
    {
        if (! ctype_digit($raw)) {
            throw InvalidCronExpression::malformed($expression);
        }

        $value = (int) $raw;

        if ($value < $min || $value > $max) {
            throw InvalidCronExpression::malformed($expression);
        }

        return $value;
    }
}
