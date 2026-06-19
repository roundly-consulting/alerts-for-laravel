<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Checks;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\CheckResult;
use RoundlyConsulting\Alerts\Notifications\HealthCheckFailedNotification;
use Throwable;

/**
 * Verifies a queue connection resolves and its backlog is under a threshold.
 */
final class QueueCheck extends Check
{
    private ?string $connection = null;

    private ?string $queue = null;

    private ?int $maxSize = null;

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

    public function onQueue(string $queue): self
    {
        $this->queue = $queue;

        return $this;
    }

    public function maxSize(int $maxSize): self
    {
        $this->maxSize = $maxSize;

        return $this;
    }

    public function check(): CheckResult
    {
        $connection = $this->connection ?? (string) config('queue.default');

        try {
            $size = Queue::connection($connection)->size($this->queue);
        } catch (Throwable $e) {
            return CheckResult::failed(
                (string) __('alerts::checks.queue_unreachable', ['connection' => $connection]),
                ['connection' => $connection, 'error' => $e->getMessage()],
            );
        }

        if ($this->maxSize !== null && $size > $this->maxSize) {
            return CheckResult::warning(
                (string) __('alerts::checks.queue_backlog', ['connection' => $connection, 'size' => $size]),
                ['connection' => $connection, 'size' => $size, 'max_size' => $this->maxSize],
            );
        }

        return CheckResult::ok(
            (string) __('alerts::checks.queue_ok', ['connection' => $connection, 'size' => $size]),
            ['connection' => $connection, 'size' => $size],
        );
    }

    public function notification(object $notifiable): Notification
    {
        return new HealthCheckFailedNotification($this);
    }
}
