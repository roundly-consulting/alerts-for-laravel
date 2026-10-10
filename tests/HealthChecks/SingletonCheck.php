<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Tests\HealthChecks;

use Illuminate\Notifications\Notification;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\CheckResult;

/**
 * A check scheduled at most once per notifiable, whatever its meta: `uniqueBy([])`.
 */
class SingletonCheck extends Check
{
    /**
     * @return list<string>
     */
    public function uniqueBy(): ?array
    {
        return [];
    }

    public function check(): CheckResult
    {
        return CheckResult::ok();
    }

    public function notification(object $notifiable): Notification
    {
        return new ExampleNotification($notifiable);
    }
}
