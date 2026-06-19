<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Checks\DatabaseCheck;
use RoundlyConsulting\Alerts\Enums\Status;

it('passes when the database is reachable', function () {
    $result = DatabaseCheck::make()->check();

    expect($result->status)->toBe(Status::Ok)
        ->and($result->meta['connection'])->toBe(config('database.default'));
});

it('fails when the connection does not exist', function () {
    $result = DatabaseCheck::make()->connection('does-not-exist')->check();

    expect($result->status)->toBe(Status::Failed)
        ->and($result->meta)->toHaveKey('error');
});

it('builds a default notification', function () {
    $check = DatabaseCheck::make();

    expect($check->notification(new stdClass))
        ->toBeInstanceOf(RoundlyConsulting\Alerts\Notifications\HealthCheckFailedNotification::class);
});
