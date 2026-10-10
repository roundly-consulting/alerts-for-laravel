<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Tests\HealthChecks;

use Illuminate\Notifications\Notification;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\CheckResult;

/**
 * A meta-reading check scheduled once per watched service: its identity is the notifiable,
 * the check and `meta.service`.
 */
class ServiceCheck extends Check
{
    public static bool $ok = true;

    /**
     * @return list<string>
     */
    public function uniqueBy(): ?array
    {
        return ['service'];
    }

    public function check(): CheckResult
    {
        return new CheckResult(static::$ok, static::$ok ? 'up' : 'down');
    }

    public function notification(object $notifiable): Notification
    {
        return new ExampleNotification($notifiable);
    }
}
