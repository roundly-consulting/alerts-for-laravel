<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Alerts\Database\Factories\AlertFactory;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Support\HealthCheckModel;

/**
 * @property int $id
 * @property string $notifiable_type
 * @property int $notifiable_id
 * @property int $health_check_id
 * @property Status $status
 * @property int $escalation_level
 * @property string|null $message
 * @property array<string, mixed>|null $meta
 * @property \Carbon\CarbonInterface $triggered_at
 * @property \Carbon\CarbonInterface|null $recovered_at
 * @property int|null $open_slot {@see self::OPEN_SLOT} while the pipeline holds this alert open
 * @property \Carbon\CarbonInterface|null $created_at
 * @property \Carbon\CarbonInterface|null $updated_at
 * @property \Carbon\CarbonInterface|null $deleted_at
 * @property-read HealthCheck|null $healthCheck
 *
 * Deliberately not `final`: `alerts.alert` documents pointing the package at your
 * own model, which means extending this one.
 */
class Alert extends Model
{
    /** @use HasFactory<AlertFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * The `open_slot` value of the alert the pipeline holds open for a check. A unique
     * (health_check_id, open_slot) index admits one such alert per check; recovery
     * clears the slot.
     */
    public const int OPEN_SLOT = 1;

    protected $guarded = [];

    /**
     * @return MorphTo<Model, $this>
     */
    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<HealthCheck, $this>
     */
    public function healthCheck(): BelongsTo
    {
        return $this->belongsTo(HealthCheckModel::class(), 'health_check_id');
    }

    /**
     * @param  Builder<Alert>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('recovered_at');
    }

    /**
     * @param  Builder<Alert>  $query
     */
    public function scopeRecovered(Builder $query): void
    {
        $query->whereNotNull('recovered_at');
    }

    protected static function newFactory(): AlertFactory
    {
        return AlertFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => Status::class,
            'escalation_level' => 'int',
            'triggered_at' => 'datetime',
            'recovered_at' => 'datetime',
            'open_slot' => 'int',
            'meta' => 'array',
        ];
    }
}
