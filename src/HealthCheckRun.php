<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RoundlyConsulting\Alerts\Database\Factories\HealthCheckRunFactory;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Support\HealthCheckModel;

/**
 * @property int $id
 * @property int $health_check_id
 * @property Status $status
 * @property int $duration_ms
 * @property string|null $message
 * @property array<string, mixed>|null $meta
 * @property \Carbon\CarbonInterface $ran_at
 * @property-read HealthCheck|null $healthCheck
 *
 * Deliberately not `final`: `alerts.history.model` documents pointing the package at
 * your own model, which means extending this one.
 */
class HealthCheckRun extends Model
{
    /** @use HasFactory<HealthCheckRunFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return BelongsTo<HealthCheck, $this>
     */
    public function healthCheck(): BelongsTo
    {
        return $this->belongsTo(HealthCheckModel::class(), 'health_check_id');
    }

    protected static function newFactory(): HealthCheckRunFactory
    {
        return HealthCheckRunFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => Status::class,
            'duration_ms' => 'int',
            'meta' => 'array',
            'ran_at' => 'datetime',
        ];
    }
}
