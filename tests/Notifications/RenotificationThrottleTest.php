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
