<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RoundlyConsulting\Alerts\Database\Factories\AlertSilenceFactory;

/**
 * A maintenance-window / mute record. The `key` may be a specific HealthCheck key,
 * a tag, or '*' for everything, optionally scoped to a single notifiable.
 *
 * @property int $id
 * @property string $key
 * @property string|null $notifiable_type
 * @property int|null $notifiable_id
 * @property string|null $reason
 * @property CarbonInterface|null $starts_at
 * @property CarbonInterface|null $ends_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 *
 * Deliberately not `final`: `alerts.silence-model` documents pointing the package at
 * your own model, which means extending this one.
 */
class AlertSilence extends Model
{
    /** @use HasFactory<AlertSilenceFactory> */
    use HasFactory;

    public const string GLOBAL_KEY = '*';

    protected $guarded = [];

    /**
     * @return MorphTo<Model, $this>
     */
    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Silences active at the given moment (started and not yet ended).
     *
     * @param  Builder<AlertSilence>  $query
     */
    public function scopeActive(Builder $query, CarbonInterface $at): void
    {
        $query
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $at));
    }

    /**
     * Silences matching any of the given keys, scoped to the notifiable when given
     * (a global silence with no notifiable always matches).
     *
     * @param  Builder<AlertSilence>  $query
     * @param  list<string>  $keys
     */
    public function scopeMatching(Builder $query, array $keys, ?Model $notifiable = null): void
    {
        $query->whereIn('key', $keys);

        if ($notifiable !== null) {
            $query->where(function (Builder $q) use ($notifiable): void {
                $q->whereNull('notifiable_id')
                    ->orWhere(function (Builder $scoped) use ($notifiable): void {
                        $scoped->where('notifiable_type', $notifiable->getMorphClass())
                            ->where('notifiable_id', $notifiable->getKey());
                    });
            });
        }
    }

    protected static function newFactory(): AlertSilenceFactory
    {
        return AlertSilenceFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
