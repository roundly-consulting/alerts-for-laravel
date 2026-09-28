<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Exceptions;

use Exception;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\HealthCheck;

final class InvalidHealthCheck extends Exception
{
    public static function doesntExtendBaseCheck(object $check): self
    {
        $checkClassName = $check::class;
        $baseCheckClass = Check::class;

        return new self("Class [$checkClassName] does not extend base check class [$baseCheckClass]");
    }

    public static function notRegistered(string $key): self
    {
        return new self("No health check is registered for key [$key]. Register it via Health::check().");
    }

    public static function notScheduledFor(HealthCheck $healthCheck, Model $notifiable): self
    {
        return new self(sprintf(
            'Health check #%s [%s] is not scheduled for [%s:%s].',
            (string) $healthCheck->getKey(),
            $healthCheck->health_check,
            $notifiable->getMorphClass(),
            (string) $notifiable->getKey(),
        ));
    }
}
