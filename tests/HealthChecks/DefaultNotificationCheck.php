<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Tests\HealthChecks;

use Illuminate\Notifications\Notification;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\CheckResult;
use RoundlyConsulting\Alerts\Notifications\HealthCheckFailedNotification;

/**
 * A failing check that uses the bundled default notification, so per-check/level
 * channel routing can be asserted through its via().
 */
class DefaultNotificationCheck extends Check
{
    public function check(): CheckResult
    {
        return CheckResult::failed('down');
    }

    public function notification(object $notifiable): Notification
    {
        return new HealthCheckFailedNotification($this, channels: $this->channels);
    }
}
