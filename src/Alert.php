<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Alerts\Database\Factories\AlertFactory;

/**
 * @property int $id
 * @property string $notifiable_type
 * @property int $notifiable_id
 * @property int $health_check_id
 * @property string|null $message
 * @property array<string, mixed>|null $meta
 * @property \Carbon\CarbonInterface $triggered_at
 * @property \Carbon\CarbonInterface|null $recovered_at
 * @property \Carbon\CarbonInterface|null $created_at
 * @property \Carbon\CarbonInterface|null $updated_at
 * @property \Carbon\CarbonInterface|null $deleted_at
 */
final class Alert extends Model
{
    /** @use HasFactory<AlertFactory> */
    use HasFactory;

    use SoftDeletes;

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
        return $this->belongsTo(HealthCheck::class);
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
            'triggered_at' => 'datetime',
            'recovered_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
