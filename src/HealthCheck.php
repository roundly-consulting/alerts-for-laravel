<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts;

use Closure;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Alerts\Database\Factories\HealthCheckFactory;
use RoundlyConsulting\Alerts\Exceptions\InvalidHealthCheck;
use RoundlyConsulting\Alerts\Exceptions\InvalidNotifiableForHealthCheck;
use RoundlyConsulting\Alerts\Facades\Health as HealthFacade;
use RoundlyConsulting\Alerts\Interfaces\HasNotifiablesForAlerts;
use RoundlyConsulting\Alerts\Jobs\HealthCheckJob;

/**
 * @property int $id
 * @property string $notifiable_type
 * @property int $notifiable_id
 * @property string $health_check
 * @property string $frequency
 * @property int $max_attempts
 * @property int $decay_minutes
 * @property array<string, mixed>|null $meta
 * @property \Carbon\CarbonInterface|null $created_at
 * @property \Carbon\CarbonInterface|null $updated_at
 * @property \Carbon\CarbonInterface|null $deleted_at
 * @property-read Model|null $notifiable
 */
final class HealthCheck extends Model
{
    /** @use HasFactory<HealthCheckFactory> */
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

    public function forEachNotifiable(Closure $callback): void
    {
        $notifiable = $this->notifiable;

        if (! $notifiable instanceof HasNotifiablesForAlerts) {
            throw InvalidNotifiableForHealthCheck::doesntImplementInterface($notifiable);
        }

        $notifiable->forEachNotifiableForAlerts($callback);
    }

    public function healthCheck(): Check
    {
        $check = HealthFacade::find($this->health_check);

        if ($check === null) {
            throw InvalidHealthCheck::notRegistered($this->health_check);
        }

        $className = $check::class;

        return new $className(
            healthCheck: $this,
        );
    }

    public function isDue(): bool
    {
        return (new CronExpression($this->frequency))->isDue(
            currentTime: now(),
        );
    }

    public function dispatchHealthCheckJob(): void
    {
        /** @var class-string<HealthCheckJob> $job */
        $job = config('alerts.job', HealthCheckJob::class);

        $job::dispatch($this);
    }

    protected static function newFactory(): HealthCheckFactory
    {
        return HealthCheckFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_attempts' => 'int',
            'decay_minutes' => 'int',
            'meta' => 'array',
        ];
    }
}
