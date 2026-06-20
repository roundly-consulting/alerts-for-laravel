<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Checks;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\CheckResult;
use RoundlyConsulting\Alerts\Notifications\HealthCheckFailedNotification;
use Throwable;

/**
 * Verifies a database connection is reachable by opening its PDO connection.
 */
final class DatabaseCheck extends Check
{
    private ?string $connection = null;

    public static function make(?string $connection = null): self
    {
        $check = new self;
        $check->connection = $connection;

        return $check;
    }

    public function connection(string $connection): self
    {
        $this->connection = $connection;

        return $this;
    }

    public function check(): CheckResult
    {
        $connection = $this->connection ?? (string) config('database.default');

        try {
            DB::connection($connection)->getPdo();
        } catch (Throwable $e) {
            return CheckResult::failed(
                (string) __('alerts::checks.database_unreachable', ['connection' => $connection]),
                ['connection' => $connection, 'error' => $e->getMessage()],
            );
        }

        return CheckResult::ok(
            (string) __('alerts::checks.database_reachable', ['connection' => $connection]),
            ['connection' => $connection],
        );
    }

    public function notification(object $notifiable): Notification
    {
        return new HealthCheckFailedNotification($this, channels: $this->channels);
    }
}
