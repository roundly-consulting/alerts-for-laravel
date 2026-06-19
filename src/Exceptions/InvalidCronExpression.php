<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Exceptions;

use Exception;

final class InvalidCronExpression extends Exception
{
    public static function malformed(string $expression): self
    {
        return new self("The cron expression [$expression] is not a valid 5-field schedule.");
    }
}
