<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\Alerts\HealthCheck;

/**
 * @mixin Model
 */
trait UsesHealthChecks
{
    /**
     * @return MorphMany<HealthCheck, $this>
     */
    public function healthChecks(): MorphMany
    {
        /** @var class-string<HealthCheck> $model */
        $model = config('alerts.health-check', HealthCheck::class);

        return $this->morphMany($model, 'notifiable');
    }

    /**
     * @return MorphMany<Alert, $this>
     */
    public function alerts(): MorphMany
    {
        /** @var class-string<Alert> $model */
        $model = config('alerts.alert', Alert::class);

        return $this->morphMany($model, 'notifiable');
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function createHealthCheck(
        string $healthCheckKey,
        string $frequency,
        int $maxAttempts = 1,
        int $decayMinutes = 1,
        array $meta = [],
    ): HealthCheck {
        return HealthCheck::create([
            'notifiable_type' => $this->getMorphClass(),
            'notifiable_id' => $this->getKey(),
            'health_check' => $healthCheckKey,
            'frequency' => $frequency,
            'max_attempts' => $maxAttempts,
            'decay_minutes' => $decayMinutes,
            'meta' => $meta,
        ]);
    }
}
