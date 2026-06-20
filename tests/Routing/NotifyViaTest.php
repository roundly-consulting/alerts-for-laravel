<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Alerts\Actions\RunHealthCheckAction;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Notifications\HealthCheckFailedNotification;
use RoundlyConsulting\Alerts\Support\MonitorOptions;
use RoundlyConsulting\Alerts\Tests\HealthChecks\DefaultNotificationCheck;
use RoundlyConsulting\Alerts\Tests\Models\GroupedTeam;
use RoundlyConsulting\Alerts\Tests\Models\User;

function routedCheck(GroupedTeam $team, array $meta): HealthCheck
{
    Health::check(DefaultNotificationCheck::class);

    return HealthCheck::create([
        'notifiable_type' => $team->getMorphClass(),
        'notifiable_id' => $team->getKey(),
        'health_check' => 'default_notification_check',
        'frequency' => '* * * * *',
        'max_attempts' => 10,
        'decay_minutes' => 1,
        'meta' => $meta,
    ]);
}

it('returns the declared channels from the default notification via', function () {
    $check = new DefaultNotificationCheck;
    $check->via(['mail', 'slack']);

    $notification = $check->notification(new User);

    expect($notification->via(new User))->toBe(['mail', 'slack'])
        ->and($check->channels())->toBe(['mail', 'slack']);
});

it('falls back to the default channels when none are declared', function () {
    $notification = new HealthCheckFailedNotification(new DefaultNotificationCheck);

    expect($notification->via(new User))->toBe(['mail', 'database']);
});

it('resolves level-specific channels with global and default fallback', function () {
    $options = MonitorOptions::fromMeta([
        MonitorOptions::NOTIFY_VIA => ['mail'],
        MonitorOptions::NOTIFY_VIA_LEVELS => [3 => ['slack', 'sms']],
    ]);

    expect($options->channelsForLevel(3, ['mail', 'database']))->toBe(['slack', 'sms'])
        ->and($options->channelsForLevel(1, ['mail', 'database']))->toBe(['mail'])
        ->and(MonitorOptions::fromMeta([])->channelsForLevel(0, ['mail', 'database']))->toBe(['mail', 'database']);
});

it('applies the resolved channels through the action for the default group', function () {
    Notification::fake();

    $team = GroupedTeam::create();
    $user = User::create(['email' => 'owner@x.com']);

    $healthCheck = routedCheck($team, [MonitorOptions::NOTIFY_VIA => ['mail', 'slack']]);

    app(RunHealthCheckAction::class)->execute($healthCheck);

    Notification::assertSentTo(
        $user,
        HealthCheckFailedNotification::class,
        fn (HealthCheckFailedNotification $notification): bool => $notification->via($user) === ['mail', 'slack'],
    );
});
