<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use RoundlyConsulting\Alerts\DataTransferObjects\ScheduleHealthCheckData;
use RoundlyConsulting\Alerts\Exceptions\InvalidHealthCheck;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\HealthManager;
use RoundlyConsulting\Alerts\Support\HealthCheckModel;
use RoundlyConsulting\Alerts\Support\Identity;
use RoundlyConsulting\Alerts\Support\ScheduleState;

/**
 * Persists a scheduled health check against a notifiable, folding the declarative
 * monitor options (failAfter, timeout, routing, escalation) into the row's `meta`.
 *
 * A check whose `uniqueBy()` is null gets a new row every time. A check that declares an
 * identity keeps one live schedule per notifiable, check and named meta values: scheduling it
 * again rebuilds that row's settings from the DTO, writes only the ones that differ, and resets
 * the flap counters only when one did — an identical re-schedule is a no-op. The returned row
 * says what happened through Eloquent's own flags (`wasRecentlyCreated`, `wasChanged()`,
 * `getChanges()`, `getPrevious()`).
 *
 * The unique `identity_hash` index makes it race-safe: of two schedules of one identity that
 * both find nothing, one insert wins and the other updates the winner's row (last write wins).
 */
final readonly class ScheduleHealthCheckAction
{
    public function __construct(
        private HealthManager $health,
    ) {}

    public function execute(Model $notifiable, ScheduleHealthCheckData $data): HealthCheck
    {
        $key = $data->key();
        $settings = ScheduleState::fromData($data, $data->cronFrequency());
        $uniqueBy = ScheduleState::uniqueBy($this->health, $data->check, $key);

        $columns = [
            'notifiable_type' => $notifiable->getMorphClass(),
            'notifiable_id' => $notifiable->getKey(),
            'health_check' => $key,
            ...$settings,
        ];

        if ($uniqueBy === null) {
            return HealthCheckModel::class()::create($columns);
        }

        $discriminators = Identity::discriminators($key, $uniqueBy, $data->metaWithOptions());
        $hash = Identity::schedule($notifiable->getMorphClass(), $notifiable->getKey(), $key, $discriminators);

        $row = $this->holder($hash) ?? $this->adopt($notifiable, $key, $uniqueBy, $discriminators, $hash);

        if ($row !== null) {
            return $this->replace($row, $settings);
        }

        try {
            return $this->insert([...$columns, 'identity_hash' => $hash]);
        } catch (UniqueConstraintViolationException) {
            // Another schedule of the same identity inserted it after this one looked.
        }

        $holder = HealthCheckModel::query()->withTrashed()->where('identity_hash', $hash)->first();

        if ($holder !== null && ! $holder->trashed()) {
            return $this->replace($holder, $settings);
        }

        // Soft-deleted straight through the model, the holder kept its identity: release it.
        if ($holder !== null) {
            $this->release($holder);
        }

        return $this->insert([...$columns, 'identity_hash' => $hash]);
    }

    private function holder(string $hash): ?HealthCheck
    {
        return HealthCheckModel::query()->where('identity_hash', $hash)->first();
    }

    /**
     * A schedule written before the identity existed (no hash) for the same notifiable, check
     * and discriminator values — the oldest first — takes the identity, compare-and-swap, so
     * two schedules cannot both adopt it.
     *
     * @param  list<string>  $uniqueBy
     * @param  array<string, string|null>  $discriminators
     */
    private function adopt(Model $notifiable, string $key, array $uniqueBy, array $discriminators, string $hash): ?HealthCheck
    {
        $legacy = HealthCheckModel::query()
            ->where('notifiable_type', $notifiable->getMorphClass())
            ->where('notifiable_id', $notifiable->getKey())
            ->where('health_check', $key)
            ->whereNotNull('frequency')
            ->whereNull('identity_hash')
            ->oldest('id')
            ->get();

        foreach ($legacy as $row) {
            if (! $this->sameIdentity($key, $uniqueBy, $row, $discriminators)) {
                continue;
            }

            try {
                $taken = HealthCheckModel::query()->getConnection()->transaction(
                    fn (): int => HealthCheckModel::query()
                        ->whereKey($row->getKey())
                        ->whereNull('identity_hash')
                        ->update(['identity_hash' => $hash]),
                );
            } catch (UniqueConstraintViolationException) {
                return null;
            }

            if ($taken === 1) {
                return $row->refresh();
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $uniqueBy
     * @param  array<string, string|null>  $discriminators
     */
    private function sameIdentity(string $key, array $uniqueBy, HealthCheck $row, array $discriminators): bool
    {
        try {
            return Identity::discriminators($key, $uniqueBy, $row->meta) === $discriminators;
        } catch (InvalidHealthCheck) {
            // A legacy row whose value is not a scalar is not this identity.
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function replace(HealthCheck $row, array $settings): HealthCheck
    {
        $changes = ScheduleState::changes($row, $settings);

        if ($changes !== []) {
            $row->forceFill([...$changes, ...ScheduleState::RESET_COUNTERS])->save();
        }

        return $row;
    }

    /**
     * In its own transaction (a savepoint when the caller already holds one), so a lost race on
     * postgres rolls back this insert alone, not the caller's transaction.
     *
     * @param  array<string, mixed>  $columns
     */
    private function insert(array $columns): HealthCheck
    {
        return HealthCheckModel::query()->getConnection()->transaction(function () use ($columns): HealthCheck {
            $row = HealthCheckModel::new()->forceFill($columns);
            $row->save();

            return $row;
        });
    }

    private function release(HealthCheck $holder): void
    {
        HealthCheckModel::query()
            ->withTrashed()
            ->whereKey($holder->getKey())
            ->update(['identity_hash' => null]);
    }
}
