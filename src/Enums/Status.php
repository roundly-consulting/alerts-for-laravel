<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Enums;

use RoundlyConsulting\Enums\Helpers;

enum Status: string
{
    use Helpers;

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
