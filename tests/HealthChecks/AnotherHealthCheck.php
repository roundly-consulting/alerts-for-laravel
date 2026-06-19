<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Tests\HealthChecks;

use Illuminate\Notifications\Notification;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\CheckResult;

class AnotherHealthCheck extends Check
{
    public function check(): CheckResult
    {
        return CheckResult::ok();
    }

    public function notification(object $notifiable): Notification
    {
        // $notifiable constructor parameter is optional just for testing
        return new ExampleNotification($notifiable);
    }
}
