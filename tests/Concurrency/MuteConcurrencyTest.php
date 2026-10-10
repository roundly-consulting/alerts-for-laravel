<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\AlertSilence;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\Support\AlertSilenceModel;
use RoundlyConsulting\Alerts\Tests\Models\Team;

/**
 * `mute()` reads the silence by its identity and inserts when there is none. Two mutes of the
 * same identity can both read "none": the unique `identity_hash` index lets one insert win,
 * and the loser updates the winner's row instead of stacking a second one.
 *
 * The competitor runs — and commits — from the moment this mute opens its insert transaction,
 * after it has already looked and found nothing: the race, with the interleaving pinned.
 */
it('keeps one silence when two mutes of the same identity race', function (?bool $scoped): void {
    $team = $scoped ? Team::create() : null;
    $interleaved = false;

    AlertSilenceModel::query()->getConnection()->beforeStartingTransaction(function () use (&$interleaved, $team): void {
        if ($interleaved) {
            return;
        }

        $interleaved = true;

        Health::silences()->mute('db', until: now()->addHour(), for: $team, reason: 'competitor');
    });

    $silence = Health::silences()->mute('db', until: now()->addHours(2), for: $team, reason: 'winner');

    expect($interleaved)->toBeTrue()
        ->and(AlertSilence::query()->count())->toBe(1)
        ->and($silence->getKey())->toBe(AlertSilence::query()->sole()->getKey())
        ->and(AlertSilence::query()->sole()->reason)->toBe('winner');
})->with(['global' => false, 'scoped' => true]);
