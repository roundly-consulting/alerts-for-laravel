<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Exceptions;

use Exception;
use RoundlyConsulting\Alerts\HealthCheck;

final class InvalidCronExpression extends Exception
{
    public static function malformed(string $expression): self
    {
        return new self("The cron expression [$expression] is not a valid 5-field schedule.");
    }

    public static function skipped(HealthCheck $healthCheck, self $previous): self
    {
        return new self(sprintf(
            'Health check #%s [%s] was skipped by the scheduler: %s',
            (string) $healthCheck->getKey(),
            $healthCheck->health_check,
            $previous->getMessage(),
        ), previous: $previous);
    }
}
