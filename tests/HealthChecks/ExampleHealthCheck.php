<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Tests\HealthChecks;

use Illuminate\Notifications\Notification;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\CheckResult;
use RoundlyConsulting\Alerts\Enums\Status;

class ExampleHealthCheck extends Check
{
    public static bool $ok = true;

    public static ?Status $status = null;

    public static string $message = 'Everything seems ok';

    public static array $meta = ['checked' => true];

    public function check(): CheckResult
    {
        $status = static::$status ?? static::$ok;

        return new CheckResult($status, static::$message, [
            'definition-meta' => $this->healthCheck?->meta,
            'meta' => static::$meta,
        ]);
    }

    public function notification(object $notifiable): Notification
    {
        // $notifiable constructor parameter is optional just for testing
        return new ExampleNotification($notifiable);
    }
}
