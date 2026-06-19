<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Tests\HealthChecks;

use Illuminate\Notifications\Notification;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\CheckResult;

class ExampleHealthCheck extends Check
{
    public static bool $ok = true;

    public static string $message = 'Everything seems ok';

    public static array $meta = ['checked' => true];

    public function check(): CheckResult
    {
        return new CheckResult(static::$ok, static::$message, [
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
