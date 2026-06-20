<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Tests\HealthChecks;

use Illuminate\Notifications\Notification;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\CheckResult;
use RoundlyConsulting\Alerts\Notifications\HealthCheckFailedNotification;
use RuntimeException;

class ThrowingHealthCheck extends Check
{
    public function check(): CheckResult
    {
        throw new RuntimeException('upstream exploded');
    }

    public function notification(object $notifiable): Notification
    {
        return new HealthCheckFailedNotification($this);
    }
}
