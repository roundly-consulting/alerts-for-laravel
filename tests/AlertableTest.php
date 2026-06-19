<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleNotification;
use RoundlyConsulting\Alerts\Tests\Models\User;

it('generates name from class name by default', function () {
    $healthCheck = new ExampleHealthCheck;

    expect($healthCheck->name())->toBe('Example Health Check');
});

it('generates key from class name by default', function () {
    $healthCheck = new ExampleHealthCheck;

    expect($healthCheck->key())->toBe('example_health_check');
});

it('has default description', function () {
    $healthCheck = new ExampleHealthCheck;

    expect($healthCheck->description())->toBe('Perform Example Health Check and trigger notification when necessary.');
});

it('has default frequencies', function () {
    $healthCheck = new ExampleHealthCheck;

    expect($healthCheck->frequencies())
        ->toBeArray()
        ->toBe([
            '* * * * *' => __('Every Minute'),
            '*/5 * * * *' => __('Every 5 Minutes'),
            '*/10 * * * *' => __('Every 10 Minutes'),
            '@hourly' => __('Every Hour'),
            '@daily' => __('Every Day'),
            '@weekly' => __('Every Week'),
            '@monthly' => __('Every Month'),
            '@yearly' => __('Every Year'),
        ]);
});

it('checks whether we should send notification to notifiable', function () {
    $john = User::create(['email' => 'john@doe.com']);
    $jane = User::create(['email' => 'jane@doe.com']);

    $healthCheck = new ExampleHealthCheck(
        healthCheck: new HealthCheck([
            'decay_minutes' => 1,
            'max_attempts' => 2,
        ]),
    );

    Notification::fake();

    expect([
        $healthCheck->notify($john),
        $healthCheck->notify($john),
        $healthCheck->notify($john),
        $healthCheck->notify($jane),
    ])->toBe([
        true,
        true,
        false,
        true,
    ]);

    Notification::assertSentTimes(ExampleNotification::class, 3);
});

it('has returns defined notification for notifiable', function () {
    $healthCheck = new ExampleHealthCheck;
    $user = User::create(['email' => 'john@doe.com']);

    expect($healthCheck->notification($user))
        ->toBeInstanceOf(ExampleNotification::class)
        ->notifiable->toBe($user);
});
