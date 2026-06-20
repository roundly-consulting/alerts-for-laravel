<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Alerts\Actions\RunHealthCheckAction;
use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Events\HealthCheckEscalated;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Support\MonitorOptions;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleNotification;
use RoundlyConsulting\Alerts\Tests\Models\GroupedTeam;
use RoundlyConsulting\Alerts\Tests\Models\User;

function escalatingCheck(GroupedTeam $team): HealthCheck
{
    Health::check(ExampleHealthCheck::class);

    return HealthCheck::create([
        'notifiable_type' => $team->getMorphClass(),
        'notifiable_id' => $team->getKey(),
        'health_check' => 'example_health_check',
        'frequency' => '* * * * *',
        'max_attempts' => 10,
        'decay_minutes' => 1,
        'meta' => [MonitorOptions::ESCALATION => [1 => 'owner', 3 => 'team']],
    ]);
}

beforeEach(function () {
    ExampleHealthCheck::$status = Status::Failed;
});

it('notifies the owner group at level one and fires escalated from zero to one', function () {
    Notification::fake();
    Event::fake([HealthCheckEscalated::class]);

    $team = GroupedTeam::create();
    $owner = User::create(['email' => 'owner@x.com']);
    $member = User::create(['email' => 'team@x.com']);

    $healthCheck = escalatingCheck($team);

    app(RunHealthCheckAction::class)->execute($healthCheck);

    expect(Alert::first()->escalation_level)->toBe(1);
    Notification::assertSentTo($owner, ExampleNotification::class);
    Notification::assertNotSentTo($member, ExampleNotification::class);
    Event::assertDispatched(
        HealthCheckEscalated::class,
        fn (HealthCheckEscalated $e): bool => $e->fromLevel === 0 && $e->toLevel === 1,
    );
});

it('escalates to the team group at the higher threshold notifying only that group', function () {
    Notification::fake();

    $team = GroupedTeam::create();
    $owner = User::create(['email' => 'owner@x.com']);
    $member = User::create(['email' => 'team@x.com']);

    $healthCheck = escalatingCheck($team);

    app(RunHealthCheckAction::class)->execute($healthCheck); // level 1
    app(RunHealthCheckAction::class)->execute($healthCheck); // still level 1 (below 3)

    Notification::fake(); // reset records

    app(RunHealthCheckAction::class)->execute($healthCheck); // third failure -> level 3

    expect(Alert::first()->escalation_level)->toBe(3);
    Notification::assertSentTo($member, ExampleNotification::class);
    Notification::assertNotSentTo($owner, ExampleNotification::class);
});

it('does not escalate again or refire at the same level', function () {
    Notification::fake();
    Event::fake([HealthCheckEscalated::class]);

    $team = GroupedTeam::create();
    User::create(['email' => 'owner@x.com']);

    $healthCheck = escalatingCheck($team);

    app(RunHealthCheckAction::class)->execute($healthCheck);
    app(RunHealthCheckAction::class)->execute($healthCheck);

    expect(Alert::first()->escalation_level)->toBe(1);
    Event::assertDispatchedTimes(HealthCheckEscalated::class, 1);
});

it('keeps escalation level zero and notifies the default group without a policy', function () {
    Notification::fake();
    Event::fake([HealthCheckEscalated::class]);

    $team = GroupedTeam::create();
    $owner = User::create(['email' => 'owner@x.com']);

    Health::check(ExampleHealthCheck::class);
    $healthCheck = HealthCheck::create([
        'notifiable_type' => $team->getMorphClass(),
        'notifiable_id' => $team->getKey(),
        'health_check' => 'example_health_check',
        'frequency' => '* * * * *',
        'max_attempts' => 1,
        'decay_minutes' => 1,
        'meta' => [],
    ]);

    app(RunHealthCheckAction::class)->execute($healthCheck);

    expect(Alert::first()->escalation_level)->toBe(0);
    Notification::assertSentTo($owner, ExampleNotification::class);
    Event::assertNotDispatched(HealthCheckEscalated::class);
});

it('resets escalation level to zero on recovery', function () {
    Notification::fake();

    $team = GroupedTeam::create();
    User::create(['email' => 'owner@x.com']);

    $healthCheck = escalatingCheck($team);

    app(RunHealthCheckAction::class)->execute($healthCheck);
    expect(Alert::first()->escalation_level)->toBe(1);

    ExampleHealthCheck::$status = Status::Ok;
    app(RunHealthCheckAction::class)->execute($healthCheck);

    expect(Alert::first()->escalation_level)->toBe(0);
});
