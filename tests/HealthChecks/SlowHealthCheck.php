<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Tests\HealthChecks;

use Illuminate\Notifications\Notification;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\CheckResult;
use RoundlyConsulting\Alerts\Notifications\HealthCheckFailedNotification;

class SlowHealthCheck extends Check
{
    public static int $sleepMicroseconds = 2000;

    public function check(): CheckResult
    {
        usleep(static::$sleepMicroseconds);

        return CheckResult::ok('done');
    }

    public function notification(object $notifiable): Notification
    {
        return new HealthCheckFailedNotification($this);
    }
}
