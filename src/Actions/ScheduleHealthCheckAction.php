<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Alerts\DataTransferObjects\ScheduleHealthCheckData;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Support\HealthCheckModel;

/**
 * Persists a scheduled health check against a notifiable, folding the declarative
 * monitor options (failAfter, timeout, routing, escalation) into the row's `meta`.
 */
final readonly class ScheduleHealthCheckAction
{
    public function execute(Model $notifiable, ScheduleHealthCheckData $data): HealthCheck
    {
        return HealthCheckModel::class()::create([
            'notifiable_type' => $notifiable->getMorphClass(),
            'notifiable_id' => $notifiable->getKey(),
            'health_check' => $data->key(),
            'frequency' => $data->cronFrequency(),
            'max_attempts' => $data->maxAttempts,
            'decay_minutes' => $data->decayMinutes,
            'tags' => $data->tags === [] ? null : $data->tags,
            'meta' => $data->metaWithOptions(),
        ]);
    }
}
