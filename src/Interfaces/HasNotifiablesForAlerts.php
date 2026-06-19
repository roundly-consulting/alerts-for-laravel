<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Interfaces;

use Closure;

interface HasNotifiablesForAlerts
{
    public function forEachNotifiableForAlerts(Closure $callback): void;
}
