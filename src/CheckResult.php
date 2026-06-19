<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts;

final class CheckResult
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly bool $isOk = true,
        public readonly string $message = '',
        public readonly array $meta = [],
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function ok(string $message = '', array $meta = []): self
    {
        return new self(true, $message, $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function failed(string $message = '', array $meta = []): self
    {
        return new self(false, $message, $meta);
    }
}
