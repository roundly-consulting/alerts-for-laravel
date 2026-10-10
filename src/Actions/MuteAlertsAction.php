<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Actions;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use RoundlyConsulting\Alerts\AlertSilence;
use RoundlyConsulting\Alerts\Support\AlertSilenceModel;
use RoundlyConsulting\Alerts\Support\Identity;

/**
 * Opens a maintenance window: alert notifications for a check key, a tag, or `'*'`
 * are suppressed until `$until` (or until unmuted), optionally for one notifiable.
 *
 * An upsert on the silence's identity — its key and its scope: muting the same thing again
 * updates the one row (`reason` and `ends_at`, extending or shortening the window) instead of
 * stacking another. A global and a scoped silence of one key stay distinct.
 *
 * Race-safe through the unique `identity_hash` index: of two mutes that both find nothing,
 * one insert wins and the other updates the winner's row. The row is written through the
 * configured model, so a host's model events fire.
 */
final readonly class MuteAlertsAction
{
    public function execute(
        string $key,
        ?CarbonInterface $until = null,
        ?Model $notifiable = null,
        ?string $reason = null,
    ): AlertSilence {
        $hash = Identity::silence($key, $notifiable?->getMorphClass(), $notifiable?->getKey());

        $window = ['reason' => $reason, 'starts_at' => null, 'ends_at' => $until];

        $held = $this->holder($hash);

        if ($held !== null) {
            return $this->update($held, $window);
        }

        $attributes = [
            'key' => $key,
            'notifiable_type' => $notifiable?->getMorphClass(),
            'notifiable_id' => $notifiable?->getKey(),
            ...$window,
            'identity_hash' => $hash,
        ];

        try {
            return $this->insert($attributes);
        } catch (UniqueConstraintViolationException) {
            // Another mute of the same identity inserted it after this one looked.
        }

        $held = $this->holder($hash);

        // Unmuted again in between: nothing holds the identity any more.
        return $held === null ? $this->insert($attributes) : $this->update($held, $window);
    }

    private function holder(string $hash): ?AlertSilence
    {
        return AlertSilenceModel::query()->where('identity_hash', $hash)->first();
    }

    /**
     * In its own transaction (a savepoint when the caller already holds one), so a lost race on
     * postgres rolls back this insert alone, not the caller's transaction.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function insert(array $attributes): AlertSilence
    {
        return AlertSilenceModel::query()->getConnection()->transaction(function () use ($attributes): AlertSilence {
            $silence = AlertSilenceModel::new()->forceFill($attributes);
            $silence->save();

            return $silence;
        });
    }

    /**
     * @param  array<string, mixed>  $window
     */
    private function update(AlertSilence $silence, array $window): AlertSilence
    {
        $silence->forceFill($window)->save();

        return $silence;
    }
}
