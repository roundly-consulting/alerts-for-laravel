<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Status\CheckStatus;

it('reports ok state', function () {
    expect((new CheckStatus('a', 'A', Status::Ok))->isOk())->toBeTrue()
        ->and((new CheckStatus('a', 'A', Status::Failed))->isOk())->toBeFalse();
});

it('serialises to an array with the alert timestamp', function () {
    $at = now();
    $array = (new CheckStatus('a', 'A', Status::Warning, $at, 'slow'))->toArray();

    expect($array)
        ->toHaveKey('key', 'a')
        ->toHaveKey('status', 'warning')
        ->toHaveKey('message', 'slow')
        ->and($array['last_alert_at'])->toBe($at->toIso8601String());
});
