<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Alerts\Actions\RunHealthCheckAction;
use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\Alerts\AlertSilence;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Events\HealthCheckFailed;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Support\MonitorOptions;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleNotification;
use RoundlyConsulting\Alerts\Tests\Models\Team;
use RoundlyConsulting\Alerts\Tests\Models\User;

beforeEach(function () {
    ExampleHealthCheck::$status = Status::Failed;
});

function mutedHealthCheck(Team $team, array $tags = []): HealthCheck
{
    Health::check(ExampleHealthCheck::class);

    return HealthCheck::create([
        'notifiable_type' => $team->getMorphClass(),
        'notifiable_id' => $team->getKey(),
        'health_check' => 'example_health_check',
        'frequency' => '* * * * *',
        'max_attempts' => 1,
        'decay_minutes' => 1,
        'tags' => $tags === [] ? null : $tags,
        'meta' => [],
    ]);
}

it('records the run but suppresses notifications while muted by key', function () {
    Notification::fake();
    Event::fake();

    $team = Team::create();
    User::create(['email' => 'a@b.com']);
    $healthCheck = mutedHealthCheck($team);

    Health::mute('example_health_check');

    expect(Health::isMuted('example_health_check'))->toBeTrue();

    app(RunHealthCheckAction::class)->execute($healthCheck);

    expect($healthCheck->runs()->count())->toBe(1)
        ->and((bool) Alert::first()->meta[MonitorOptions::MUTED])->toBeTrue();
    Notification::assertNothingSent();
    Event::assertNotDispatched(HealthCheckFailed::class);
});

it('mutes every check carrying a tag', function () {
    Notification::fake();

    $team = Team::create();
    User::create(['email' => 'a@b.com']);
    $healthCheck = mutedHealthCheck($team, ['critical']);

    Health::mute('critical');

    app(RunHealthCheckAction::class)->execute($healthCheck);

    Notification::assertNothingSent();
});

it('mutes everything with the global key', function () {
    Notification::fake();

    $team = Team::create();
    User::create(['email' => 'a@b.com']);
    $healthCheck = mutedHealthCheck($team);

    Health::mute('*');

    app(RunHealthCheckAction::class)->execute($healthCheck);

    Notification::assertNothingSent();
});

it('expires a mute after its until moment', function () {
    Notification::fake();

    Carbon::setTestNow('2026-06-20 12:00:00');

    $team = Team::create();
    User::create(['email' => 'a@b.com']);
    $healthCheck = mutedHealthCheck($team);

    Health::mute('example_health_check', until: now()->addMinutes(5));

    Carbon::setTestNow('2026-06-20 12:10:00');

    expect(Health::isMuted('example_health_check'))->toBeFalse();

    app(RunHealthCheckAction::class)->execute($healthCheck);

    Notification::assertSentTo(User::first(), ExampleNotification::class);

    Carbon::setTestNow();
});

it('restores notifications after unmute', function () {
    Notification::fake();

    $team = Team::create();
    User::create(['email' => 'a@b.com']);
    $healthCheck = mutedHealthCheck($team);

    Health::mute('example_health_check');
    Health::unmute('example_health_check');

    expect(Health::isMuted('example_health_check'))->toBeFalse();

    app(RunHealthCheckAction::class)->execute($healthCheck);

    Notification::assertSentTo(User::first(), ExampleNotification::class);
});

it('only affects the scoped notifiable for a notifiable-scoped mute', function () {
    $team = Team::create();
    $other = Team::create();

    Health::mute('example_health_check', notifiable: $team);

    expect(Health::isMuted('example_health_check', $team))->toBeTrue()
        ->and(Health::isMuted('example_health_check', $other))->toBeFalse();
});

it('unmutes a notifiable-scoped silence without touching global ones', function () {
    $team = Team::create();

    Health::mute('example_health_check');
    Health::mute('example_health_check', notifiable: $team);

    Health::unmute('example_health_check', $team);

    expect(Health::isMuted('example_health_check', $team))->toBeTrue() // global still active
        ->and(AlertSilence::query()->whereNotNull('notifiable_id')->count())->toBe(0);
});

it('exposes the notifiable relation on a silence', function () {
    $team = Team::create();

    $silence = AlertSilence::factory()->create([
        'key' => 'db',
        'notifiable_type' => $team->getMorphClass(),
        'notifiable_id' => $team->getKey(),
    ]);

    expect($silence->notifiable->is($team))->toBeTrue();
});

it('ignores silences when the master switch is off', function () {
    config()->set('alerts.silence', false);

    AlertSilence::factory()->create(['key' => 'example_health_check']);

    expect(Health::isMuted('example_health_check'))->toBeFalse();
});
