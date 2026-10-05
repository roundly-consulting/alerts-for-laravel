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
 * `*`, lists (`1,2,3`), ranges (`1-5`), steps (`* / 5`, `1-30/2`), day and month
 * names (`MON-FRI`, `JAN,JUL`, case-insensitive) and the common named aliases
 * (`@hourly`, `@daily`, …). It deliberately covers only what the
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

    /** @var array<string, int> */
    private const MONTH_NAMES = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    /** @var array<string, int> */
    private const DAY_NAMES = [
        'sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6,
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

    /**
     * Whether both day fields are restricted (neither is `*`): standard cron then runs on
     * a day that matches EITHER of them, not only on a day that matches both.
     */
    private bool $eitherDay;

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
        $this->months = $this->parseField($parts[3], 1, 12, $expression, self::MONTH_NAMES);
        $this->daysOfWeek = $this->parseField($parts[4], 0, 7, $expression, self::DAY_NAMES);
        $this->eitherDay = $parts[2] !== '*' && $parts[4] !== '*';
    }

    /**
     * Throw InvalidCronExpression unless the expression can be evaluated, returning it
     * trimmed. Called when a schedule is saved, so a bad expression fails the caller
     * instead of every later scheduler tick.
     *
     * @throws InvalidCronExpression
     */
    public static function validate(string $expression): string
    {
        new self($expression);

        return mb_trim($expression);
    }

    public function isDue(?CarbonInterface $now = null): bool
    {
        $now = $now ?? Carbon::now();

        return in_array((int) $now->minute, $this->minutes, true)
            && in_array((int) $now->hour, $this->hours, true)
            && in_array((int) $now->month, $this->months, true)
            && $this->dayMatches($now);
    }

    private function dayMatches(CarbonInterface $at): bool
    {
        $dayOfMonth = in_array((int) $at->day, $this->daysOfMonth, true);

        // Cron treats both 0 and 7 as Sunday; normalise the current weekday set.
        $weekday = (int) $at->dayOfWeek;
        $dayOfWeek = in_array($weekday, $this->daysOfWeek, true)
            || ($weekday === 0 && in_array(7, $this->daysOfWeek, true));

        return $this->eitherDay ? $dayOfMonth || $dayOfWeek : $dayOfMonth && $dayOfWeek;
    }

    /**
     * @param  array<string, int>  $names
     * @return list<int>
     */
    private function parseField(string $field, int $min, int $max, string $expression, array $names = []): array
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

            [$start, $end] = $this->resolveRange($segment, $min, $max, $expression, $names);

            for ($value = $start; $value <= $end; $value += $step) {
                $values[$value] = $value;
            }
        }

        ksort($values);

        return array_values($values);
    }

    /**
     * @param  array<string, int>  $names
     * @return array{0: int, 1: int}
     */
    private function resolveRange(string $segment, int $min, int $max, string $expression, array $names): array
    {
        if ($segment === '*' || $segment === '') {
            return [$min, $max];
        }

        if (str_contains($segment, '-')) {
            [$startRaw, $endRaw] = explode('-', $segment, 2);
            $start = $this->parseNumber($startRaw, $min, $max, $expression, $names);
            $end = $this->parseNumber($endRaw, $min, $max, $expression, $names);

            if ($start > $end) {
                throw InvalidCronExpression::malformed($expression);
            }

            return [$start, $end];
        }

        $value = $this->parseNumber($segment, $min, $max, $expression, $names);

        return [$value, $value];
    }

    /**
     * @param  array<string, int>  $names
     */
    private function parseNumber(string $raw, int $min, int $max, string $expression, array $names): int
    {
        $named = $names[mb_strtolower($raw)] ?? null;

        if ($named !== null) {
            return $named;
        }

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
