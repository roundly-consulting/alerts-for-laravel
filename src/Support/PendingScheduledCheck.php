<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Alerts\DataTransferObjects\ScheduleHealthCheckData;
use RoundlyConsulting\Alerts\Enums\Frequency;
use RoundlyConsulting\Alerts\HealthCheck;

/**
 * Fluent builder for attaching a scheduled health check to a notifiable model.
 */
final class PendingScheduledCheck
{
    private string $frequency = '* * * * *';

    private int $maxAttempts = 1;

    private int $decayMinutes = 1;

    /** @var array<string, mixed> */
    private array $meta = [];

    public function __construct(
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
            meta: $this->meta,
        );

        /** @var class-string<HealthCheck> $model */
        $model = config('alerts.health-check', HealthCheck::class);

        return $model::create([
            'notifiable_type' => $this->notifiable->getMorphClass(),
            'notifiable_id' => $this->notifiable->getKey(),
            'health_check' => $data->key(),
            'frequency' => $data->cronFrequency(),
            'max_attempts' => $data->maxAttempts,
            'decay_minutes' => $data->decayMinutes,
            'meta' => $data->meta,
        ]);
    }
}
