<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Enums;

enum Status: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Failed = 'failed';
    case Skipped = 'skipped';

    /**
     * Whether this status should open an alert and notify.
     */
    public function isAlertable(): bool
    {
        return match ($this) {
            self::Warning, self::Failed => true,
            self::Ok, self::Skipped => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Ok => (string) __('alerts::status.ok'),
            self::Warning => (string) __('alerts::status.warning'),
            self::Failed => (string) __('alerts::status.failed'),
            self::Skipped => (string) __('alerts::status.skipped'),
        };
    }

    /**
     * Severity ordering used to roll up an overall status (higher = worse).
     */
    public function severity(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Skipped => 1,
            self::Warning => 2,
            self::Failed => 3,
        };
    }
}
