<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\CheckResult;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\Health as HealthManager;

it('exposes its parent health manager', function () {
    $pending = Health::define('k', fn () => CheckResult::ok());

    expect($pending->health())->toBeInstanceOf(HealthManager::class)
        ->and($pending->key)->toBe('k');
});
