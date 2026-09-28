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

it('carries the failing result\'s message when sent by the pipeline', function () {
    Illuminate\Support\Facades\Notification::fake();

    $team = RoundlyConsulting\Alerts\Tests\Models\Team::create();
    $user = RoundlyConsulting\Alerts\Tests\Models\User::create(['email' => 'ops@x.com']);

    RoundlyConsulting\Alerts\Facades\Health::define('redis-up', fn () => RoundlyConsulting\Alerts\CheckResult::failed('Redis down: connection refused'));
    RoundlyConsulting\Alerts\Facades\Health::for($team)->run(RoundlyConsulting\Alerts\Facades\Health::find('redis-up'));

    Illuminate\Support\Facades\Notification::assertSentTo(
        $user,
        HealthCheckFailedNotification::class,
        fn (HealthCheckFailedNotification $notification): bool => $notification->toArray($user)['message'] === 'Redis down: connection refused'
            && in_array('Redis down: connection refused', $notification->toMail($user)->introLines, true),
    );
});
