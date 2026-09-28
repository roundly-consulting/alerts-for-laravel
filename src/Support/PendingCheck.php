<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Support;

use Closure;
use RoundlyConsulting\Alerts\DataTransferObjects\ScheduleHealthCheckData;
use RoundlyConsulting\Alerts\HealthManager;

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

    private int $failAfter = 1;

    private int $recoverAfter = 1;

    private ?int $timeout = null;

    /** @var list<string> */
    private array $tags = [];

    /** @var list<string>|null */
    private ?array $notifyVia = null;

    /** @var array<int, list<string>> */
    private array $notifyViaLevels = [];

    /** @var array<int, string> */
    private array $escalation = [];

    public function __construct(
        private readonly HealthManager $health,
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

    public function failAfter(int $consecutiveFailures): self
    {
        $this->failAfter = max(1, $consecutiveFailures);

        return $this;
    }

    public function recoverAfter(int $consecutiveSuccesses): self
    {
        $this->recoverAfter = max(1, $consecutiveSuccesses);

        return $this;
    }

    public function timeout(int $seconds): self
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * @param  list<string>  $tags
     */
    public function tags(array $tags): self
    {
        $this->tags = array_values(array_unique($tags));

        return $this;
    }

    /**
     * @param  list<string>  $channels
     */
    public function notifyVia(array $channels, ?int $level = null): self
    {
        if ($level === null) {
            $this->notifyVia = $channels;
        } else {
            $this->notifyViaLevels[$level] = $channels;
        }

        return $this;
    }

    /**
     * @param  array<int, string>  $policy
     */
    public function escalate(array $policy): self
    {
        $this->escalation = $policy;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function resolvedTags(): array
    {
        return $this->tags;
    }

    /**
     * The declarative monitor options as a meta payload under their reserved keys.
     *
     * @return array<string, mixed>
     */
    public function resolvedMeta(): array
    {
        $data = new ScheduleHealthCheckData(
            check: $this->key,
            failAfter: $this->failAfter,
            recoverAfter: $this->recoverAfter,
            timeout: $this->timeout,
            notifyVia: $this->notifyVia,
            notifyViaLevels: $this->notifyViaLevels,
            escalation: $this->escalation,
        );

        return $data->metaWithOptions();
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

    public function health(): HealthManager
    {
        return $this->health;
    }
}
