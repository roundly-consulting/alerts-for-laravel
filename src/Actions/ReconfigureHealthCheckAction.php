<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use RoundlyConsulting\Alerts\DataTransferObjects\ReconfigureHealthCheckData;
use RoundlyConsulting\Alerts\Exceptions\InvalidHealthCheck;
use RoundlyConsulting\Alerts\Exceptions\ScheduleConflict;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\HealthManager;
use RoundlyConsulting\Alerts\Support\HealthCheckModel;
use RoundlyConsulting\Alerts\Support\ScheduleState;

/**
 * Changes a schedule in place — the PATCH that merges: what the changes leave null stays,
 * `meta` merges key by key. As for `schedule()`, only the settings that differ are written and
 * the flap counters reset only when one did; no change is no write. The check itself is not
 * changeable — another check is another schedule.
 *
 * A row of another notifiable is refused (the scope is the security boundary). For a check
 * that declares `uniqueBy()`, the identity follows the new meta; moving onto the identity of
 * another live schedule throws ScheduleConflict and changes nothing.
 */
final readonly class ReconfigureHealthCheckAction
{
    public function __construct(
        private HealthManager $health,
    ) {}

    /**
     * @throws InvalidHealthCheck for a row scheduled against another notifiable, or a
     *                            discriminator that is not a scalar or null
     * @throws ScheduleConflict
     */
    public function execute(Model $notifiable, HealthCheck $row, ReconfigureHealthCheckData $changes): HealthCheck
    {
        if (! $row->isScheduledFor($notifiable)) {
            throw InvalidHealthCheck::notScheduledFor($row, $notifiable);
        }

        $settings = ScheduleState::merged($row, $changes);
        $diff = ScheduleState::changes($row, $settings);

        if ($diff === []) {
            return $row;
        }

        $uniqueBy = $this->uniqueBy($row);
        $hash = $this->identity($row, $uniqueBy, $settings, $diff);
        $metaKeys = is_array($uniqueBy) ? $uniqueBy : [];

        if ($hash !== $row->identity_hash) {
            $diff['identity_hash'] = $hash;

            if ($hash !== null) {
                $this->claim($row, $hash, $metaKeys);
            }
        }

        $before = $row->getAttributes();

        try {
            HealthCheckModel::query()->getConnection()->transaction(
                fn (): bool => $row->forceFill([...$diff, ...ScheduleState::RESET_COUNTERS])->save(),
            );
        } catch (UniqueConstraintViolationException) {
            // Another schedule took the identity after this one looked.
            $row->setRawAttributes($before);

            throw ScheduleConflict::for($row, $metaKeys);
        }

        return $row;
    }

    /**
     * The check's identity keys, or false when the check is not registered here.
     *
     * @return list<string>|false|null
     */
    private function uniqueBy(HealthCheck $row): array|false|null
    {
        $check = $this->health->find($row->health_check);

        return $check === null ? false : $check->uniqueBy();
    }

    /**
     * The identity the row has after the change. For a check that is not registered here it
     * cannot be recomputed: it is kept while the meta it was read from stays, and given up when
     * the meta changes — the first `schedule()` of the new identity then adopts the row again.
     *
     * @param  list<string>|false|null  $uniqueBy
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $diff
     */
    private function identity(HealthCheck $row, array|false|null $uniqueBy, array $settings, array $diff): ?string
    {
        if ($uniqueBy === false) {
            return array_key_exists('meta', $diff) ? null : $row->identity_hash;
        }

        if ($uniqueBy === null || $settings['frequency'] === null || $row->trashed()) {
            return null;
        }

        $meta = is_array($settings['meta']) ? $settings['meta'] : null;

        return ScheduleState::identity($row->notifiable_type, $row->notifiable_id, $row->health_check, $uniqueBy, $meta);
    }

    /**
     * Refuse an identity another live schedule holds; release one a schedule soft-deleted
     * straight through the model kept.
     *
     * @param  list<string>  $uniqueBy
     */
    private function claim(HealthCheck $row, string $hash, array $uniqueBy): void
    {
        $holder = HealthCheckModel::query()
            ->withTrashed()
            ->where('identity_hash', $hash)
            ->whereKeyNot($row->getKey())
            ->first();

        if ($holder === null) {
            return;
        }

        if (! $holder->trashed()) {
            throw ScheduleConflict::for($row, $uniqueBy);
        }

        HealthCheckModel::query()->withTrashed()->whereKey($holder->getKey())->update(['identity_hash' => null]);
    }
}
