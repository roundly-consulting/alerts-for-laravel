<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Alerts\AlertSilence;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\HealthManager;

/**
 * `Health::silences()` — maintenance windows. A key is a check key, a tag, or `'*'`
 * for everything; `for:` scopes a silence to one notifiable. One silence per key and scope:
 * muting it again updates it. Each method goes through the manager, so host overrides and
 * `Health::fake()` see it.
 */
final readonly class Silences
{
    /**
     * @internal build it with `Health::silences()`
     */
    public function __construct(
        private HealthManager $health,
    ) {}

    /**
     * Mute alert notifications for a key, optionally until a moment and/or for one notifiable.
     * Muting the same key and scope again updates that silence (its reason and end) and
     * returns it, instead of adding another.
     */
    public function mute(
        string $key,
        ?CarbonInterface $until = null,
        ?Model $for = null,
        ?string $reason = null,
    ): AlertSilence {
        return $this->health->muteFor($key, $until, $for, $reason);
    }

    /**
     * Lift the silences on a key: the global ones, or only those scoped to `$for`.
     *
     * @return int how many silences were lifted
     */
    public function unmute(string $key, ?Model $for = null): int
    {
        return $this->health->unmuteFor($key, $for);
    }

    /**
     * Whether a silence on exactly this key is in force now (for `$for` when given).
     */
    public function isMuted(string $key, ?Model $for = null): bool
    {
        return $this->health->mutedFor($key, $for);
    }

    /**
     * The silences in force now: all of them, or the global ones plus those scoped to `$for`.
     *
     * @return Collection<int, AlertSilence>
     */
    public function active(?Model $for = null): Collection
    {
        return $this->health->silencesFor($for);
    }

    /**
     * Every silence in force at `$at` (default: now), loaded in one query, to ask of any
     * number of rows whether they are muted — by the runner's own rule.
     */
    public function snapshot(?CarbonInterface $at = null): SilenceSnapshot
    {
        return $this->health->silenceSnapshotFor($at ?? now());
    }

    /**
     * Whether a silence in force now mutes the row: on its check key, one of its effective
     * tags or `'*'`, global or scoped to the row's own notifiable.
     */
    public function covers(HealthCheck $row): bool
    {
        return $this->snapshot()->covers($row);
    }

    /**
     * Delete silences that ended more than `$days` ago (default: `alerts.silence-retention`).
     * Open-ended, running and future silences are never touched.
     *
     * @return int how many silences were deleted
     */
    public function prune(?int $days = null): int
    {
        return $this->health->pruneSilencesFor($days);
    }
}
