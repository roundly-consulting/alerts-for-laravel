<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Exceptions;

use InvalidArgumentException;

/**
 * A run-history retention below one day: pruning with it would delete the whole history.
 */
final class InvalidRetention extends InvalidArgumentException
{
    public static function days(int $days): self
    {
        return new self("Run history retention must be at least 1 day, [{$days}] given: pruning with it would delete the whole history.");
    }
}
