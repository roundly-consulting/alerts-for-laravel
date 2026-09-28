<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Alerts\Actions\RunHealthCheckAction;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleNotification;
use RoundlyConsulting\Alerts\Tests\Models\Team;
use RoundlyConsulting\Alerts\Tests\Models\User;

/**
 * `decay_minutes` has to survive the check being re-instantiated, because that is
 * the ONLY way it ever runs: every scheduled run is a fresh queued job that
 * re-queries the health check and its notifiable.
 *
 * Keyed on `spl_object_hash($notifiable)` — a PHP object HANDLE, not an identity
 * — the limiter got a new bucket every run and suppressed nothing at all: a check
 * on a one-minute frequency notified every minute for the whole life of an
 * incident, whatever the configured interval said.
 */
beforeEach(function (): void {
    ExampleHealthCheck::$ok = false;

    // The runner resolves a check by its registered KEY, so a row naming one that
    // was never registered is an InvalidHealthCheck.
    Health::check(ExampleHealthCheck::class);
});

it('suppresses a renotification across separate runs of the same check', function (): void {
    Notification::fake();

    $team = Team::create(['name' => 'Platform']);
    $owner = User::create(['email' => 'owner@x.com']);

    $healthCheck = $team->monitorCheck(ExampleHealthCheck::class)
        ->everyMinute()
        ->throttle(maxAttempts: 1, decayMinutes: 60)
        ->save();

    // Each run resolves the check and its notifiable afresh — what the scheduler
    // does, and what the old key could not survive.
    app(RunHealthCheckAction::class)->execute($healthCheck->fresh());
    app(RunHealthCheckAction::class)->execute($healthCheck->fresh());
    app(RunHealthCheckAction::class)->execute($healthCheck->fresh());

    Notification::assertSentToTimes($owner, ExampleNotification::class, 1);
});

it('keeps two notifiables in separate renotification buckets', function (): void {
    Notification::fake();

    $team = Team::create(['name' => 'Platform']);
    $first = User::create(['email' => 'one@x.com']);
    $second = User::create(['email' => 'two@x.com']);

    $healthCheck = $team->monitorCheck(ExampleHealthCheck::class)
        ->everyMinute()
        ->throttle(maxAttempts: 1, decayMinutes: 60)
        ->save();

    app(RunHealthCheckAction::class)->execute($healthCheck->fresh());

    // A key that ignored identity would tell one recipient and silence the other.
    Notification::assertSentToTimes($first, ExampleNotification::class, 1);
    Notification::assertSentToTimes($second, ExampleNotification::class, 1);
});

it('applies an inline check\'s throttle when it is run now', function (): void {
    Notification::fake();

    $team = Team::create(['name' => 'Platform']);
    User::create(['email' => 'owner@x.com']);

    Health::define('redis-up', fn () => RoundlyConsulting\Alerts\CheckResult::failed('Redis down'))
        ->throttle(maxAttempts: 1, decayMinutes: 15);

    foreach (range(1, 3) as $run) {
        Health::for($team)->run('redis-up');
        $this->travel(2)->minutes();
    }

    $row = $team->healthChecks()->sole();

    expect($row->max_attempts)->toBe(1)
        ->and($row->decay_minutes)->toBe(15);

    Notification::assertSentTimes(RoundlyConsulting\Alerts\Notifications\HealthCheckFailedNotification::class, 1);
});

it('starts a monitor of an inline check from its definition', function (): void {
    $team = Team::create(['name' => 'Platform']);

    Health::define('redis-up', fn () => true)
        ->throttle(maxAttempts: 2, decayMinutes: 15)
        ->failAfter(3)
        ->timeout(4)
        ->escalate([3 => 'oncall']);

    $inherited = Health::for($team)->monitor('redis-up')->save();
    $overridden = Health::for($team)->monitor('redis-up')->throttle(5, 30)->failAfter(1)->save();

    expect($inherited->max_attempts)->toBe(2)
        ->and($inherited->decay_minutes)->toBe(15)
        ->and($inherited->options()->failAfter())->toBe(3)
        ->and($inherited->options()->timeout())->toBe(4)
        ->and($inherited->options()->escalation())->toBe([3 => 'oncall'])
        ->and($overridden->max_attempts)->toBe(5)
        ->and($overridden->decay_minutes)->toBe(30)
        ->and($overridden->options()->failAfter())->toBe(1);
});

it('keeps an on-demand row in step with the inline definition', function (): void {
    $team = Team::create(['name' => 'Platform']);

    Health::define('redis-up', fn () => true)->throttle(1, 5);
    Health::for($team)->run('redis-up');

    Health::define('redis-up', fn () => true)->throttle(1, 30)->failAfter(2);
    Health::for($team)->run('redis-up');

    $row = $team->healthChecks()->sole();

    expect($row->decay_minutes)->toBe(30)
        ->and($row->options()->failAfter())->toBe(2)
        ->and($row->consecutive_successes)->toBe(2);
});
