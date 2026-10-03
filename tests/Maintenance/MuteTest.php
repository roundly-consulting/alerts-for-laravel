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
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

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

    Health::silences()->mute('example_health_check');

    expect(Health::silences()->isMuted('example_health_check'))->toBeTrue();

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

    Health::silences()->mute('critical');

    app(RunHealthCheckAction::class)->execute($healthCheck);

    Notification::assertNothingSent();
});

it('mutes everything with the global key', function () {
    Notification::fake();

    $team = Team::create();
    User::create(['email' => 'a@b.com']);
    $healthCheck = mutedHealthCheck($team);

    Health::silences()->mute('*');

    app(RunHealthCheckAction::class)->execute($healthCheck);

    Notification::assertNothingSent();
});

it('expires a mute after its until moment', function () {
    Notification::fake();

    Carbon::setTestNow('2026-06-20 12:00:00');

    $team = Team::create();
    User::create(['email' => 'a@b.com']);
    $healthCheck = mutedHealthCheck($team);

    Health::silences()->mute('example_health_check', until: now()->addMinutes(5));

    Carbon::setTestNow('2026-06-20 12:10:00');

    expect(Health::silences()->isMuted('example_health_check'))->toBeFalse();

    app(RunHealthCheckAction::class)->execute($healthCheck);

    Notification::assertSentTo(User::first(), ExampleNotification::class);

    Carbon::setTestNow();
});

it('restores notifications after unmute', function () {
    Notification::fake();

    $team = Team::create();
    User::create(['email' => 'a@b.com']);
    $healthCheck = mutedHealthCheck($team);

    Health::silences()->mute('example_health_check');
    Health::silences()->unmute('example_health_check');

    expect(Health::silences()->isMuted('example_health_check'))->toBeFalse();

    app(RunHealthCheckAction::class)->execute($healthCheck);

    Notification::assertSentTo(User::first(), ExampleNotification::class);
});

it('only affects the scoped notifiable for a notifiable-scoped mute', function () {
    $team = Team::create();
    $other = Team::create();

    Health::silences()->mute('example_health_check', for: $team);

    expect(Health::silences()->isMuted('example_health_check', $team))->toBeTrue()
        ->and(Health::silences()->isMuted('example_health_check', $other))->toBeFalse();
});

it('unmutes a notifiable-scoped silence without touching global ones', function () {
    $team = Team::create();

    Health::silences()->mute('example_health_check');
    Health::silences()->mute('example_health_check', for: $team);

    Health::silences()->unmute('example_health_check', $team);

    expect(Health::silences()->isMuted('example_health_check', $team))->toBeTrue() // global still active
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

    expect(Health::silences()->isMuted('example_health_check'))->toBeFalse();
});

it('clears the muted flag once failures continue after the window', function () {
    Notification::fake();
    Event::fake([HealthCheckFailed::class]);

    $team = Team::create();
    $healthCheck = mutedHealthCheck($team);

    Health::silences()->mute('example_health_check', until: now()->addMinutes(30));
    app(RunHealthCheckAction::class)->execute($healthCheck->fresh());

    expect(Health::report()->checks()[0]->muted)->toBeTrue();
    Event::assertNotDispatched(HealthCheckFailed::class);

    $this->travel(31)->minutes();
    app(RunHealthCheckAction::class)->execute($healthCheck->fresh());

    Event::assertDispatched(HealthCheckFailed::class);
    expect(Health::report()->checks()[0]->muted)->toBeFalse()
        ->and(Alert::query()->sole()->meta)->not->toHaveKey(MonitorOptions::MUTED);
});

it('reads the switches from env-style strings', function (string $value, bool $on) {
    config()->set('alerts.silence', $value);
    config()->set('alerts.history.enabled', $value);

    $team = Team::create();
    $healthCheck = mutedHealthCheck($team);
    Health::silences()->mute('example_health_check');

    app(RunHealthCheckAction::class)->execute($healthCheck->fresh());

    expect(Health::silences()->isMuted('example_health_check'))->toBe($on)
        ->and($healthCheck->runs()->count())->toBe($on ? 1 : 0);
})->with([
    'one' => ['1', true],
    'true' => ['true', true],
    'on' => ['on', true],
    'yes' => ['yes', true],
    'zero' => ['0', false],
    'false' => ['false', false],
    'off' => ['off', false],
]);

it('throws on a switch typo instead of reading it as the default (strict config)', function (string $key) {
    config()->set($key, 'disabled');

    $healthCheck = mutedHealthCheck(Team::create());

    expect(fn () => app(RunHealthCheckAction::class)->execute($healthCheck->fresh()))
        ->toThrow(InvalidConfigurationException::class, "Configuration value [{$key}] must be a boolean (true/false, 1/0, on/off or yes/no), [disabled] given.");
})->with(['alerts.silence', 'alerts.history.enabled']);
