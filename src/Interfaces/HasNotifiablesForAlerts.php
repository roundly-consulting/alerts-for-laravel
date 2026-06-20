<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Interfaces;

use Closure;

interface HasNotifiablesForAlerts
{
    /**
     * Invoke the callback for each notifiable in the default alert group.
     */
    public function forEachNotifiableForAlerts(Closure $callback): void;

    /**
     * Resolve the notifiables for a named escalation group. Adopt the
     * ResolvesAlertGroups trait for a sensible default (the default group for
     * every name), then override to map groups like 'owner'/'team'/'oncall'.
     *
     * @return iterable<int, object>
     */
    public function notifiablesForAlertGroup(string $group): iterable;
}
