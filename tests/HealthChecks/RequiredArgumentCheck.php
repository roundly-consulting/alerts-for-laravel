<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Tests\HealthChecks;

use Illuminate\Notifications\Notification;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\CheckResult;
use RoundlyConsulting\Alerts\HealthCheck;

/**
 * A check that cannot be built without its configuration, so it only ever exists as the
 * instance a host registered.
 */
class RequiredArgumentCheck extends Check
{
    public function __construct(
        public readonly string $target,
        ?HealthCheck $healthCheck = null,
    ) {
        parent::__construct($healthCheck);
    }

    public function check(): CheckResult
    {
        return CheckResult::ok($this->target);
    }

    public function notification(object $notifiable): Notification
    {
        return new ExampleNotification($notifiable);
    }
}
