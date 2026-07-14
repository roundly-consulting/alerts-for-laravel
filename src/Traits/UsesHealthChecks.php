<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\Alerts\DataTransferObjects\ScheduleHealthCheckData;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Support\AlertModel;
use RoundlyConsulting\Alerts\Support\HealthCheckModel;
use RoundlyConsulting\Alerts\Support\PendingScheduledCheck;

/**
 * @mixin Model
 */
trait UsesHealthChecks
{
    use ResolvesAlertGroups;

    /**
     * @return MorphMany<HealthCheck, $this>
     */
    public function healthChecks(): MorphMany
    {
        return $this->morphMany(HealthCheckModel::class(), 'notifiable');
    }

    /**
     * @return MorphMany<Alert, $this>
     */
    public function alerts(): MorphMany
    {
        return $this->morphMany(AlertModel::class(), 'notifiable');
    }

    /**
     * @param  list<string>  $tags
     * @param  array<string, mixed>  $meta
     */
    public function createHealthCheck(
        string $healthCheckKey,
        string $frequency,
        int $maxAttempts = 1,
        int $decayMinutes = 1,
        array $tags = [],
        array $meta = [],
    ): HealthCheck {
        return HealthCheckModel::class()::create([
            'notifiable_type' => $this->getMorphClass(),
            'notifiable_id' => $this->getKey(),
            'health_check' => $healthCheckKey,
            'frequency' => $frequency,
            'max_attempts' => $maxAttempts,
            'decay_minutes' => $decayMinutes,
            'tags' => $tags === [] ? null : $tags,
            'meta' => $meta,
        ]);
    }

    /**
     * Attach a scheduled health check from a DTO.
     */
    public function monitor(ScheduleHealthCheckData $data): HealthCheck
    {
        return $this->createHealthCheck(
            healthCheckKey: $data->key(),
            frequency: $data->cronFrequency(),
            maxAttempts: $data->maxAttempts,
            decayMinutes: $data->decayMinutes,
            tags: $data->tags,
            meta: $data->metaWithOptions(),
        );
    }

    /**
     * Fluently attach a scheduled health check by its Check class-string or key.
     */
    public function monitorCheck(string $check): PendingScheduledCheck
    {
        return new PendingScheduledCheck($this, $check);
    }
}
