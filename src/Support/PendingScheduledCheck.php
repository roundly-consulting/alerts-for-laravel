<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Alerts\DataTransferObjects\ScheduleHealthCheckData;
use RoundlyConsulting\Alerts\Enums\Frequency;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\HealthManager;

/**
 * Fluent builder for attaching a scheduled health check to a notifiable model,
 * returned by `Health::for($notifiable)->monitor($check)`.
 */
final class PendingScheduledCheck
{
    private string $frequency = '* * * * *';

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

    /** @var array<string, mixed> */
    private array $meta = [];

    /**
     * @internal build it with `Health::for($notifiable)->monitor($check)`
     */
    public function __construct(
        private readonly HealthManager $health,
        private readonly Model $notifiable,
        private readonly string $check,
    ) {}

    public function everyMinute(): self
    {
        return $this->cron(Frequency::EveryMinute->value);
    }

    public function everyFiveMinutes(): self
    {
        return $this->cron(Frequency::EveryFiveMinutes->value);
    }

    public function everyTenMinutes(): self
    {
        return $this->cron(Frequency::EveryTenMinutes->value);
    }

    public function everyFifteenMinutes(): self
    {
        return $this->cron(Frequency::EveryFifteenMinutes->value);
    }

    public function everyThirtyMinutes(): self
    {
        return $this->cron(Frequency::EveryThirtyMinutes->value);
    }

    public function hourly(): self
    {
        return $this->cron(Frequency::Hourly->value);
    }

    public function daily(): self
    {
        return $this->cron(Frequency::Daily->value);
    }

    public function weekly(): self
    {
        return $this->cron(Frequency::Weekly->value);
    }

    public function monthly(): self
    {
        return $this->cron(Frequency::Monthly->value);
    }

    public function frequency(string $frequency): self
    {
        return $this->cron(Frequency::toCron($frequency));
    }

    public function cron(string $expression): self
    {
        $this->frequency = $expression;

        return $this;
    }

    public function throttle(int $maxAttempts, int $decayMinutes): self
    {
        $this->maxAttempts = $maxAttempts;
        $this->decayMinutes = $decayMinutes;

        return $this;
    }

    /**
     * Open an alert only after this many consecutive failures (debounce flapping).
     */
    public function failAfter(int $consecutiveFailures): self
    {
        $this->failAfter = max(1, $consecutiveFailures);

        return $this;
    }

    /**
     * Close an alert only after this many consecutive successes.
     */
    public function recoverAfter(int $consecutiveSuccesses): self
    {
        $this->recoverAfter = max(1, $consecutiveSuccesses);

        return $this;
    }

    /**
     * Abort or mark-failed the check after this many seconds.
     */
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
     * Channels for the default notification, optionally scoped to an escalation level.
     *
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
     * Escalation policy: consecutive-failure threshold => notifiable group name.
     *
     * @param  array<int, string>  $policy
     */
    public function escalate(array $policy): self
    {
        $this->escalation = $policy;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function meta(array $meta): self
    {
        $this->meta = $meta;

        return $this;
    }

    public function save(): HealthCheck
    {
        $data = new ScheduleHealthCheckData(
            check: $this->check,
            frequency: $this->frequency,
            maxAttempts: $this->maxAttempts,
            decayMinutes: $this->decayMinutes,
            failAfter: $this->failAfter,
            recoverAfter: $this->recoverAfter,
            timeout: $this->timeout,
            tags: $this->tags,
            notifyVia: $this->notifyVia,
            notifyViaLevels: $this->notifyViaLevels,
            escalation: $this->escalation,
            meta: $this->meta,
        );

        return $this->health->scheduleFor($this->notifiable, $data);
    }
}
