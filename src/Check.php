<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

abstract class Check
{
    protected Limit $notificationThrottle;

    public function __construct(
        public ?HealthCheck $healthCheck = null,
    ) {
        $this->notificationThrottle = $this->resolveNotificationThrottle($healthCheck);
    }

    /**
     * Return a copy of this check bound to a persisted HealthCheck row, preserving
     * any builder configuration on the original instance.
     */
    public function withHealthCheck(HealthCheck $healthCheck): static
    {
        $clone = clone $this;
        $clone->healthCheck = $healthCheck;
        $clone->notificationThrottle = $clone->resolveNotificationThrottle($healthCheck);

        return $clone;
    }

    protected function resolveNotificationThrottle(?HealthCheck $healthCheck): Limit
    {
        return $healthCheck instanceof HealthCheck
            ? Limit::perMinutes(
                decayMinutes: $healthCheck->decay_minutes,
                maxAttempts: $healthCheck->max_attempts,
            )
            : Limit::perMinute(1);
    }

    public function name(): string
    {
        return Str::headline(
            class_basename($this)
        );
    }

    public function key(): string
    {
        return Str::snake(
            class_basename($this)
        );
    }

    public function description(): string
    {
        return (string) __('Perform :name and trigger notification when necessary.', [
            'name' => $this->name(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function frequencies(): array
    {
        return [
            '* * * * *' => (string) __('Every Minute'),
            '*/5 * * * *' => (string) __('Every 5 Minutes'),
            '*/10 * * * *' => (string) __('Every 10 Minutes'),
            '@hourly' => (string) __('Every Hour'),
            '@daily' => (string) __('Every Day'),
            '@weekly' => (string) __('Every Week'),
            '@monthly' => (string) __('Every Month'),
            '@yearly' => (string) __('Every Year'),
        ];
    }

    public function notify(object $notifiable): bool
    {
        $this->notificationThrottle->by(
            key: spl_object_hash($notifiable),
        );

        return (bool) RateLimiter::attempt(
            key: $this->key().$this->notificationThrottle->key,
            maxAttempts: $this->notificationThrottle->maxAttempts,
            callback: fn () => NotificationFacade::send($notifiable, $this->notification($notifiable)),
            decaySeconds: $this->notificationThrottle->decaySeconds,
        );
    }

    abstract public function check(): CheckResult;

    abstract public function notification(object $notifiable): Notification;
}
