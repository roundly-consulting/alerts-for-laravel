<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Support;

use RoundlyConsulting\Alerts\AlertSilence;
use RoundlyConsulting\Alerts\HealthCheck;

/**
 * Every silence in force at one moment, loaded once, answering "is this row muted?" for any
 * number of rows with the runner's own rule ({@see SilenceMatcher}). Take it with
 * `Health::silences()->snapshot()`; a host may bind it `scoped` for a request.
 */
final readonly class SilenceSnapshot
{
    /**
     * @var array<string, list<AlertSilence>>
     */
    private array $byKey;

    /**
     * @internal take it with `Health::silences()->snapshot()`
     *
     * @param  iterable<AlertSilence>  $silences  the silences in force at the snapshot's moment
     */
    public function __construct(iterable $silences = [])
    {
        $byKey = [];

        foreach ($silences as $silence) {
            $byKey[$silence->key][] = $silence;
        }

        $this->byKey = $byKey;
    }

    /**
     * Whether a silence in the snapshot mutes the row: on its key, one of its effective tags or
     * `*`, global or scoped to the row's own notifiable.
     */
    public function covers(HealthCheck $row): bool
    {
        foreach (SilenceMatcher::keys($row) as $key) {
            foreach ($this->byKey[$key] ?? [] as $silence) {
                if (SilenceMatcher::reaches($silence, $row)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->byKey === [];
    }
}
