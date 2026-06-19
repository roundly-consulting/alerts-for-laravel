<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Exceptions;

use Exception;
use RoundlyConsulting\Alerts\Interfaces\HasNotifiablesForAlerts;

final class InvalidNotifiableForHealthCheck extends Exception
{
    public static function doesntImplementInterface(?object $notifiable): self
    {
        $notifiableClassName = $notifiable === null ? 'null' : $notifiable::class;
        $interfaceClassName = HasNotifiablesForAlerts::class;

        return new self("Class [$notifiableClassName] does not implement interface [$interfaceClassName]");
    }
}
