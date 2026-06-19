<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Checks;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\CheckResult;
use RoundlyConsulting\Alerts\Notifications\HealthCheckFailedNotification;
use Throwable;

/**
 * Checks free disk space on a storage disk against a configurable minimum,
 * warning as space runs low and failing once it drops below the floor.
 */
final class StorageCheck extends Check
{
    private ?string $disk = null;

    private int $minimumBytes = 1073741824; // 1 GB

    private ?int $warnBytes = null;

    public static function make(?string $disk = null): self
    {
        $check = new self;
        $check->disk = $disk;

        return $check;
    }

    public function disk(string $disk): self
    {
        $this->disk = $disk;

        return $this;
    }

    public function minimumBytes(int $bytes): self
    {
        $this->minimumBytes = $bytes;

        return $this;
    }

    public function warnBytes(int $bytes): self
    {
        $this->warnBytes = $bytes;

        return $this;
    }

    public function check(): CheckResult
    {
        $disk = $this->disk ?? (string) config('filesystems.default');

        try {
            $path = (string) Storage::disk($disk)->path('');
            $free = disk_free_space($path);
        } catch (Throwable $e) {
            return CheckResult::failed(
                (string) __('alerts::checks.storage_unreadable', ['disk' => $disk]),
                ['disk' => $disk, 'error' => $e->getMessage()],
            );
        }

        if ($free === false) {
            return CheckResult::failed(
                (string) __('alerts::checks.storage_unreadable', ['disk' => $disk]),
                ['disk' => $disk],
            );
        }

        $free = (int) $free;
        $meta = ['disk' => $disk, 'free_bytes' => $free, 'minimum_bytes' => $this->minimumBytes];

        if ($free < $this->minimumBytes) {
            return CheckResult::failed(
                (string) __('alerts::checks.storage_low', ['disk' => $disk]),
                $meta,
            );
        }

        $warnBytes = $this->warnBytes ?? $this->minimumBytes * 2;

        if ($free < $warnBytes) {
            return CheckResult::warning(
                (string) __('alerts::checks.storage_warning', ['disk' => $disk]),
                $meta,
            );
        }

        return CheckResult::ok(
            (string) __('alerts::checks.storage_ok', ['disk' => $disk]),
            $meta,
        );
    }

    public function notification(object $notifiable): Notification
    {
        return new HealthCheckFailedNotification($this);
    }
}
