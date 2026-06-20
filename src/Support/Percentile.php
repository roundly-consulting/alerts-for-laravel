<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Support;

/**
 * Tiny native percentile helper used for latency aggregation, computed in PHP so
 * uptime/latency queries behave identically across sqlite/mysql/pgsql (no DB-side
 * percentile function required).
 */
final class Percentile
{
    /**
     * Nearest-rank percentile of a set of integers. Empty input returns 0.
     *
     * @param  list<int>  $values
     */
    public static function nearestRank(array $values, float $percentile): int
    {
        if ($values === []) {
            return 0;
        }

        sort($values);

        $percentile = max(0.0, min(100.0, $percentile));
        $rank = (int) ceil(($percentile / 100) * count($values));
        $index = max(0, $rank - 1);

        return $values[$index];
    }
}
