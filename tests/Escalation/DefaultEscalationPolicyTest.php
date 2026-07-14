<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Alerts\Actions\RunHealthCheckAction;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Events\HealthCheckEscalated;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\Support\AlertModel;
use RoundlyConsulting\Alerts\Support\HealthCheckModel;
use RoundlyConsulting\Alerts\Support\MonitorOptions;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleNotification;
use RoundlyConsulting\Alerts\Tests\Models\GroupedTeam;
use RoundlyConsulting\Alerts\Tests\Models\User;

/**
 * `config/alerts.php` ships an `escalation` section documented as "Optional global
 * default escalation policy applied when a check declares none" — and the README and
 * technical docs both document the key. Nothing in the package ever read it: the
 * policy was only ever taken off the monitor row's `meta`, so a host that configured
 * the documented global default got NO escalation at all.
 *
 * These tests drive the pipeline through the SHIPPED key.
 */
function monitorWithoutOwnPolicy(GroupedTeam $team, array $meta = []): object
{
    Health::check(ExampleHealthCheck::class);

    return HealthCheckModel::class()::create([
        'notifiable_type' => $team->getMorphClass(),
        'notifiable_id' => $team->getKey(),
        'health_check' => 'example_health_check',
        'frequency' => '* * * * *',
        'max_attempts' => 10,
        'decay_minutes' => 1,
        'meta' => $meta,
    ]);
}

beforeEach(function (): void {
    ExampleHealthCheck::$status = Status::Failed;
});

it('applies the globally configured escalation policy to a check that declares none', function (): void {
    Notification::fake();
    Event::fake([HealthCheckEscalated::class]);

    config()->set('alerts.escalation', [1 => 'owner', 3 => 'team']);

    $team = GroupedTeam::create();
    $owner = User::create(['email' => 'owner@x.com']);
    $member = User::create(['email' => 'team@x.com']);

    $monitor = monitorWithoutOwnPolicy($team);

    app(RunHealthCheckAction::class)->execute($monitor);

    $alert = AlertModel::query()->sole();

    expect($alert->escalation_level)->toBe(1);

    Event::assertDispatched(
        HealthCheckEscalated::class,
        fn (HealthCheckEscalated $event): bool => $event->fromLevel === 0 && $event->toLevel === 1,
    );

    // Level 1 pages the 'owner' group only — the global policy really is driving the
    // routing, not just the level counter.
    Notification::assertSentTo($owner, ExampleNotification::class);
    Notification::assertNotSentTo($member, ExampleNotification::class);
});

it('escalates through the globally configured levels as the failure persists', function (): void {
    Notification::fake();

    config()->set('alerts.escalation', [1 => 'owner', 3 => 'team']);

    $team = GroupedTeam::create();
    $owner = User::create(['email' => 'owner@x.com']);
    User::create(['email' => 'team@x.com']);

    $monitor = monitorWithoutOwnPolicy($team);

    foreach (range(1, 3) as $ignored) {
        app(RunHealthCheckAction::class)->execute(
            HealthCheckModel::query()->findOrFail($monitor->getKey()),
        );
    }

    expect(AlertModel::query()->sole()->escalation_level)->toBe(3);
});

it('lets a check declare its own policy over the global default', function (): void {
    Notification::fake();

    config()->set('alerts.escalation', [1 => 'owner']);

    $team = GroupedTeam::create();
    User::create(['email' => 'owner@x.com']);

    // The row declares a policy that only opens at 2 consecutive failures.
    $monitor = monitorWithoutOwnPolicy($team, [MonitorOptions::ESCALATION => [2 => 'team']]);

    app(RunHealthCheckAction::class)->execute($monitor);

    expect(AlertModel::query()->sole()->escalation_level)->toBe(0);
});

it('preserves the no-policy behaviour when the global default is empty', function (): void {
    Notification::fake();

    $team = GroupedTeam::create();
    $member = User::create(['email' => 'team@x.com']);

    $monitor = monitorWithoutOwnPolicy($team);

    app(RunHealthCheckAction::class)->execute($monitor);

    // No policy anywhere: the alert stays at level 0 and the default group is notified.
    expect(AlertModel::query()->sole()->escalation_level)->toBe(0);

    Notification::assertSentTo($member, ExampleNotification::class);
});

it('reads the default policy off the monitor options', function (): void {
    config()->set('alerts.escalation', [2 => 'oncall']);

    $team = GroupedTeam::create();
    $monitor = monitorWithoutOwnPolicy($team);

    expect($monitor->options()->escalation())->toBe([2 => 'oncall'])
        ->and($monitor->options()->levelForFailures(2))->toBe(2);
});
