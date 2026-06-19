<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Checks\DatabaseCheck;
use RoundlyConsulting\Alerts\Notifications\HealthCheckFailedNotification;

it('sends via mail and database', function () {
    $notification = new HealthCheckFailedNotification(DatabaseCheck::make());

    expect($notification->via(new stdClass))->toBe(['mail', 'database']);
});

it('renders a mail message', function () {
    $notification = new HealthCheckFailedNotification(DatabaseCheck::make(), 'Connection refused');

    $mail = $notification->toMail(new stdClass);

    expect($mail->subject)->toContain('Database Check')
        ->and($mail->introLines)->toContain('Connection refused');
});

it('exposes an array payload', function () {
    $payload = (new HealthCheckFailedNotification(DatabaseCheck::make(), 'oops'))->toArray(new stdClass);

    expect($payload)
        ->toHaveKey('check', 'database_check')
        ->toHaveKey('name', 'Database Check')
        ->toHaveKey('message', 'oops');
});
