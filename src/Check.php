<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
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
     * Default tags carried by every instance of this check. Override to group and
     * filter checks; merged with any tags declared on the scheduled row.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        return [];
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

    /**
     * Channels applied to the bundled default notification for this run, resolved by
     * RunHealthCheckAction off the scheduled row for the current escalation level.
     *
     * @var list<string>|null
     */
    protected ?array $channels = null;

    /**
     * Set the channel list used by the bundled default notification. A custom
     * notification controls its own via() and ignores this.
     *
     * @param  list<string>|null  $channels
     */
    public function via(?array $channels): static
    {
        $this->channels = $channels;

        return $this;
    }

    /**
     * @return list<string>|null
     */
    public function channels(): ?array
    {
        return $this->channels;
    }

    public function notify(object $notifiable): bool
    {
        $this->notificationThrottle->by(
            key: $this->throttleKey($notifiable),
        );

        return (bool) RateLimiter::attempt(
            key: $this->key().$this->notificationThrottle->key,
            maxAttempts: $this->notificationThrottle->maxAttempts,
            callback: fn () => NotificationFacade::send($notifiable, $this->notification($notifiable)),
            decaySeconds: $this->notificationThrottle->decaySeconds,
        );
    }

    /**
     * The renotification bucket: a stable identity for (this scheduled check,
     * this notifiable).
     *
     * This used to be `spl_object_hash($notifiable)`, which is a PHP OBJECT
     * HANDLE — not an identity. Every scheduled run is a fresh queued job that
     * re-queries the health check and its notifiable, so the handle differed on
     * every run and `decay_minutes` suppressed nothing at all: a `* * * * *`
     * check notified once a minute for the whole life of an incident, whatever
     * the configured interval said. (The handle is not even stable within one
     * process — PHP reuses it once an object is freed.)
     *
     * The health check's own id is part of the key too, because one check class
     * may be scheduled several times against the same notifiable with different
     * `meta` — watching a different service each time. Those are separate jobs
     * and must renotify separately.
     */
    protected function throttleKey(object $notifiable): string
    {
        $scope = $notifiable instanceof Model
            ? $notifiable->getMorphClass().':'.((string) $notifiable->getKey())
            : $notifiable::class;

        return $scope.':'.((string) ($this->healthCheck?->getKey() ?? ''));
    }

    abstract public function check(): CheckResult;

    abstract public function notification(object $notifiable): Notification;
}
