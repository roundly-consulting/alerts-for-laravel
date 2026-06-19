<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts;

use RoundlyConsulting\Alerts\Enums\Status;

final class CheckResult
{
    public readonly Status $status;

    /**
     * Backward-compatible derived flag: true only for a healthy result.
     */
    public bool $isOk {
        get => $this->status === Status::Ok;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        bool|Status $status = true,
        public readonly string $message = '',
        public readonly array $meta = [],
    ) {
        $this->status = $status instanceof Status
            ? $status
            : ($status ? Status::Ok : Status::Failed);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function ok(string $message = '', array $meta = []): self
    {
        return new self(Status::Ok, $message, $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function warning(string $message = '', array $meta = []): self
    {
        return new self(Status::Warning, $message, $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function failed(string $message = '', array $meta = []): self
    {
        return new self(Status::Failed, $message, $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function skipped(string $message = '', array $meta = []): self
    {
        return new self(Status::Skipped, $message, $meta);
    }
}
