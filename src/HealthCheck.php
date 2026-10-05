<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Alerts\Database\Factories\HealthCheckFactory;
use RoundlyConsulting\Alerts\Exceptions\InvalidHealthCheck;
use RoundlyConsulting\Alerts\Exceptions\InvalidNotifiableForHealthCheck;
use RoundlyConsulting\Alerts\Facades\Health as HealthFacade;
use RoundlyConsulting\Alerts\Interfaces\HasNotifiablesForAlerts;
use RoundlyConsulting\Alerts\Jobs\HealthCheckJob;
use RoundlyConsulting\Alerts\Support\CronSchedule;
use RoundlyConsulting\Alerts\Support\HealthCheckRunModel;
use RoundlyConsulting\Alerts\Support\MonitorOptions;
use RoundlyConsulting\Alerts\Support\Percentile;

/**
 * @property int $id
 * @property string $notifiable_type
 * @property int $notifiable_id
 * @property string $health_check
 * @property string|null $frequency NULL for an on-demand (run-now) row that is never scheduled
 * @property int $max_attempts
 * @property int $decay_minutes
 * @property int $consecutive_failures
 * @property int $consecutive_successes
 * @property list<string>|null $tags
 * @property array<string, mixed>|null $meta
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 * @property-read Model|null $notifiable
 *
 * Deliberately not `final`: `alerts.health-check` documents pointing the package at
 * your own model, which means extending this one.
 */
class HealthCheck extends Model
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

    /**
     * The foreign key is named explicitly: a host that points `alerts.health-check`
     * at its own model would otherwise have Eloquent derive it from that class's
     * name (`custom_health_check_id`) and every run read would miss the column the
     * migration actually creates.
     *
     * @return HasMany<HealthCheckRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(HealthCheckRunModel::class(), 'health_check_id')->latest('ran_at');
    }

    public function forEachNotifiable(Closure $callback): void
    {
        $this->notifiableForAlerts()->forEachNotifiableForAlerts($callback);
    }

    /**
     * Notifiables for a named escalation group, falling back to the default group.
     *
     * @return iterable<int, object>
     */
    public function notifiablesForGroup(string $group): iterable
    {
        return $this->notifiableForAlerts()->notifiablesForAlertGroup($group);
    }

    private function notifiableForAlerts(): HasNotifiablesForAlerts
    {
        $notifiable = $this->notifiable;

        if (! $notifiable instanceof HasNotifiablesForAlerts) {
            throw InvalidNotifiableForHealthCheck::doesntImplementInterface($notifiable);
        }

        return $notifiable;
    }

    public function healthCheck(): Check
    {
        $check = HealthFacade::find($this->health_check);

        if ($check === null) {
            throw InvalidHealthCheck::notRegistered($this->health_check);
        }

        return $check->withHealthCheck($this);
    }

    /**
     * Effective tags: row tags merged with the registered check's own tags().
     *
     * @return list<string>
     */
    public function effectiveTags(): array
    {
        $rowTags = $this->tags ?? [];

        $check = HealthFacade::find($this->health_check);
        $checkTags = $check?->tags() ?? [];

        return array_values(array_unique([...$rowTags, ...$checkTags]));
    }

    /**
     * Whether this row is scheduled against the given notifiable.
     */
    public function isScheduledFor(Model $notifiable): bool
    {
        return $this->notifiable_type === $notifiable->getMorphClass()
            && (string) $this->notifiable_id === (string) $notifiable->getKey();
    }

    /**
     * Whether the scheduler queues this row. An on-demand row — seeded the first time
     * a check is run now for an owner that has no schedule for it — never is.
     */
    public function isScheduled(): bool
    {
        return $this->frequency !== null;
    }

    /**
     * Whether the cron is due now — or, given the previous scheduler tick, at any minute
     * after it up to now, so a scheduler that ticks less often than every minute never
     * steps over a check's minute.
     */
    public function isDue(?CarbonInterface $since = null): bool
    {
        if ($this->frequency === null) {
            return false;
        }

        $cron = new CronSchedule($this->frequency);
        $now = now();

        return $since === null
            ? $cron->isDue($now)
            : $cron->isDueBetween($since, $now);
    }

    public function latestRun(): ?HealthCheckRun
    {
        return $this->runs()->first();
    }

    /**
     * Percentage of recorded runs whose status was not alertable, over an optional
     * window. Returns 100.0 when there is no history.
     */
    public function uptimePercentage(?CarbonInterface $since = null): float
    {
        $query = $this->runs();

        if ($since !== null) {
            $query->where('ran_at', '>=', $since);
        }

        $runs = $query->get(['status']);

        if ($runs->isEmpty()) {
            return 100.0;
        }

        $healthy = $runs->reject(fn (HealthCheckRun $run): bool => $run->status->isAlertable())->count();

        return round(($healthy / $runs->count()) * 100, 2);
    }

    /**
     * 95th-percentile run duration in milliseconds over an optional window.
     */
    public function p95LatencyMs(?CarbonInterface $since = null): int
    {
        $query = $this->runs();

        if ($since !== null) {
            $query->where('ran_at', '>=', $since);
        }

        /** @var list<int> $durations */
        $durations = $query->get(['duration_ms'])
            ->map(fn (HealthCheckRun $run): int => $run->duration_ms)
            ->all();

        return Percentile::nearestRank($durations, 95);
    }

    public function dispatchHealthCheckJob(): void
    {
        $job = config('alerts.job');

        // Not set — absent, null or blank — runs the packaged job.
        if ($job === null || (is_string($job) && trim($job) === '')) {
            $job = HealthCheckJob::class;
        }

        /** @var class-string<HealthCheckJob> $job */
        $job::dispatch($this);
    }

    /**
     * The per-monitor declarations from the row's `meta`, falling back to the
     * globally configured escalation policy when the row declares none.
     */
    public function options(): MonitorOptions
    {
        /** @var array<int|string, string> $default */
        $default = config('alerts.escalation', []);

        return MonitorOptions::fromMeta($this->meta, $default);
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
            'consecutive_failures' => 'int',
            'consecutive_successes' => 'int',
            'tags' => 'array',
            'meta' => 'array',
        ];
    }
}
