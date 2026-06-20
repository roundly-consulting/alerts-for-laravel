<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Exceptions;

use RuntimeException;

final class CheckTimedOut extends RuntimeException
{
    public function __construct(
        public readonly string $key,
        public readonly int $seconds,
    ) {
        parent::__construct("Health check [{$key}] timed out after {$seconds}s.");
    }

    public static function after(string $key, int $seconds): self
    {
        return new self($key, $seconds);
    }
}
