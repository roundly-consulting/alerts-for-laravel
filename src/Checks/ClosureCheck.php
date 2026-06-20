<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Checks;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Notifications\Notification;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\CheckResult;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Notifications\HealthCheckFailedNotification;
use RoundlyConsulting\Alerts\Support\PendingCheck;

/**
 * A check whose logic is supplied as a closure via Health::define().
 */
final class ClosureCheck extends Check
{
    public function __construct(
        private readonly PendingCheck $pending,
        ?HealthCheck $healthCheck = null,
    ) {
        parent::__construct($healthCheck);
    }

    public function key(): string
    {
        return $this->pending->key;
    }

    public function name(): string
    {
        return $this->pending->resolvedName() ?? parent::name();
    }

    public function description(): string
    {
        return $this->pending->resolvedDescription() ?? parent::description();
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return $this->pending->resolvedTags();
    }

    /**
     * Declarative monitor options (failAfter/recoverAfter/timeout/routing/escalation)
     * as a meta payload, used to seed an ad-hoc row in Health::run().
     *
     * @return array<string, mixed>
     */
    public function pendingMeta(): array
    {
        return $this->pending->resolvedMeta();
    }

    public function check(): CheckResult
    {
        $result = ($this->pending->callback)($this);

        if ($result instanceof CheckResult) {
            return $result;
        }

        return $result ? CheckResult::ok() : CheckResult::failed();
    }

    public function notification(object $notifiable): Notification
    {
        $notification = $this->pending->resolvedNotification();

        if (is_string($notification)) {
            $instance = new $notification($this);

            if (! $instance instanceof Notification) {
                return new HealthCheckFailedNotification($this, channels: $this->channels);
            }

            return $instance;
        }

        if ($notification !== null) {
            $instance = $notification($this, $notifiable);

            if ($instance instanceof Notification) {
                return $instance;
            }
        }

        return new HealthCheckFailedNotification($this, channels: $this->channels);
    }

    protected function resolveNotificationThrottle(?HealthCheck $healthCheck): Limit
    {
        if ($healthCheck instanceof HealthCheck) {
            return parent::resolveNotificationThrottle($healthCheck);
        }

        return Limit::perMinutes(
            decayMinutes: $this->pending->decayMinutes(),
            maxAttempts: $this->pending->maxAttempts(),
        );
    }
}
