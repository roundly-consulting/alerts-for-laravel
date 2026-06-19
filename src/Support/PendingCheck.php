<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Support;

use Closure;
use RoundlyConsulting\Alerts\Health;

/**
 * Mutable configuration for an inline closure-based check, returned by
 * Health::define() so options can be set fluently after registration.
 */
final class PendingCheck
{
    private ?string $name = null;

    private ?string $description = null;

    /** @var class-string|Closure|null */
    private string|Closure|null $notification = null;

    private int $maxAttempts = 1;

    private int $decayMinutes = 1;

    public function __construct(
        private readonly Health $health,
        public readonly string $key,
        public readonly Closure $callback,
    ) {}

    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @param  class-string|Closure  $notification
     */
    public function notifyUsing(string|Closure $notification): self
    {
        $this->notification = $notification;

        return $this;
    }

    public function throttle(int $maxAttempts, int $decayMinutes): self
    {
        $this->maxAttempts = $maxAttempts;
        $this->decayMinutes = $decayMinutes;

        return $this;
    }

    public function resolvedName(): ?string
    {
        return $this->name;
    }

    public function resolvedDescription(): ?string
    {
        return $this->description;
    }

    /**
     * @return class-string|Closure|null
     */
    public function resolvedNotification(): string|Closure|null
    {
        return $this->notification;
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function decayMinutes(): int
    {
        return $this->decayMinutes;
    }

    public function health(): Health
    {
        return $this->health;
    }
}
