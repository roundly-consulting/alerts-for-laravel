<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Traits;

use Closure;
use RoundlyConsulting\Alerts\Interfaces\HasNotifiablesForAlerts;

/**
 * Default implementation of {@see HasNotifiablesForAlerts::notifiablesForAlertGroup()}
 * that returns the default-group notifiables for any group name. Adopting models
 * need no change unless they want to map named escalation groups ('owner', 'team',
 * 'oncall', …) to specific notifiables — in which case override the method.
 */
trait ResolvesAlertGroups
{
    /**
     * @return iterable<int, object>
     */
    public function notifiablesForAlertGroup(string $group): iterable
    {
        $notifiables = [];

        $this->forEachNotifiableForAlerts(function (object $notifiable) use (&$notifiables): void {
            $notifiables[] = $notifiable;
        });

        return $notifiables;
    }

    abstract public function forEachNotifiableForAlerts(Closure $callback): void;
}
