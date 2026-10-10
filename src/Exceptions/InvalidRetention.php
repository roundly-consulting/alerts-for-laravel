<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Exceptions;

use InvalidArgumentException;

/**
 * A retention below one day: pruning run history with it would delete the whole history, and
 * pruning silences with it would delete silences that are still running.
 */
final class InvalidRetention extends InvalidArgumentException
{
    public static function days(int $days): self
    {
        return new self("Run history retention must be at least 1 day, [{$days}] given: pruning with it would delete the whole history.");
    }

    public static function silenceDays(int $days): self
    {
        return new self("Silence retention must be at least 1 day, [{$days}] given: pruning with it would delete silences that are still running.");
    }
}
