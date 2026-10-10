<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use RoundlyConsulting\Alerts\Actions\PruneAlertSilencesAction;
use RoundlyConsulting\Alerts\AlertSilence;
use RoundlyConsulting\Alerts\AlertsServiceProvider;
use RoundlyConsulting\Alerts\Exceptions\InvalidRetention;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/**
 * Ended silences are deleted after `alerts.silence-retention` days. Open-ended and future
 * windows are never touched.
 */
beforeEach(function (): void {
    Carbon::setTestNow('2026-10-10 12:00:00');

    $this->silences = [
        'open-ended' => AlertSilence::factory()->create(['key' => 'a', 'ends_at' => null]),
        'running' => AlertSilence::factory()->create(['key' => 'b', 'ends_at' => now()->addDay()]),
        'future' => AlertSilence::factory()->create(['key' => 'c', 'starts_at' => now()->addDays(20), 'ends_at' => now()->addDays(21)]),
        'ended 1 day ago' => AlertSilence::factory()->create(['key' => 'd', 'ends_at' => now()->subDay()]),
        'ended 8 days ago' => AlertSilence::factory()->create(['key' => 'e', 'ends_at' => now()->subDays(8)]),
        'ended 40 days ago' => AlertSilence::factory()->create(['key' => 'f', 'ends_at' => now()->subDays(40)]),
    ];
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function remainingSilenceKeys(): array
{
    return AlertSilence::query()->orderBy('key')->pluck('key')->all();
}

it('deletes silences that ended more than seven days ago by default', function (): void {
    expect(Health::silences()->prune())->toBe(2)
        ->and(remainingSilenceKeys())->toBe(['a', 'b', 'c', 'd']);
});

it('takes the retention from the call, then from the config', function (): void {
    expect(Health::silences()->prune(30))->toBe(1)
        ->and(remainingSilenceKeys())->toBe(['a', 'b', 'c', 'd', 'e']);

    config()->set('alerts.silence-retention', 1);

    expect(app(PruneAlertSilencesAction::class)->execute())->toBe(1)
        ->and(remainingSilenceKeys())->toBe(['a', 'b', 'c', 'd']);
});

it('never deletes an open-ended, running or future silence', function (): void {
    Health::silences()->prune(1);

    expect(remainingSilenceKeys())->toBe(['a', 'b', 'c', 'd']);
});

it('refuses a retention below one day and deletes nothing', function (int $days): void {
    expect(fn () => Health::silences()->prune($days))
        ->toThrow(InvalidRetention::class, 'at least 1 day');

    expect(AlertSilence::query()->count())->toBe(6);
})->with([0, -5]);

it('refuses a junk silence retention, naming the key (strict config)', function (mixed $junk): void {
    config()->set('alerts.silence-retention', $junk);

    expect(fn () => Health::silences()->prune())
        ->toThrow(InvalidConfigurationException::class, 'alerts.silence-retention');

    expect(AlertSilence::query()->count())->toBe(6);
})->with(['word' => 'week', 'zero' => 0, 'decimal' => '1.5']);

it('reads a blank silence retention as the 7-day default (strict config)', function (): void {
    config()->set('alerts.silence-retention', '');

    expect(Health::silences()->prune())->toBe(2);
});

it('prunes from the command, by option or by config', function (): void {
    $this->artisan('alerts:prune-silences', ['--days' => 30])
        ->expectsOutputToContain('Pruned 1 silence(s) that ended more than 30 day(s) ago.')
        ->assertSuccessful();

    $this->artisan('alerts:prune-silences')
        ->expectsOutputToContain('Pruned 1 silence(s) that ended more than 7 day(s) ago.')
        ->assertSuccessful();

    expect(remainingSilenceKeys())->toBe(['a', 'b', 'c', 'd']);
});

it('refuses a --days that is not a whole number of at least one day and deletes nothing', function (string $days): void {
    $this->artisan('alerts:prune-silences', ['--days' => $days])
        ->expectsOutputToContain('--days must be a whole number of days, at least 1')
        ->assertFailed();

    expect(AlertSilence::query()->count())->toBe(6);
})->with(['zero' => '0', 'negative' => '-1', 'word' => 'abc', 'decimal' => '1.5']);

function scheduledAlertsCommands(): string
{
    app()->forgetInstance(Schedule::class);
    app()->singleton(Schedule::class, fn () => new Schedule);

    $provider = new AlertsServiceProvider(app());
    $provider->register();
    $provider->boot();

    return collect(app(Schedule::class)->events())
        ->map(fn ($event) => $event->command)
        ->filter()
        ->implode(' ');
}

it('schedules the silence prune daily while silences are on', function (): void {
    $schedule = new Schedule;

    (new AlertsServiceProvider(app()))->scheduleSilencePrune($schedule);

    expect($schedule->events()[0]->command)->toContain('alerts:prune-silences')
        ->and($schedule->events()[0]->expression)->toBe('0 0 * * *')
        ->and(scheduledAlertsCommands())->toContain('alerts:prune-silences');
});

it('does not schedule the silence prune while silences are off', function (): void {
    config()->set('alerts.silence', false);

    expect(scheduledAlertsCommands())->not->toContain('alerts:prune-silences')
        ->toContain('alerts:perform-health-checks');
});

it('renders the silence retention in about', function (): void {
    config()->set('alerts.silence-retention', '3');

    expect('alerts')->toLeakNoSecrets(
        secrets: ['payments-oncall'],
        mustRender: ['ON (3 day retention)'],
    );

    Artisan::call('about', ['--only' => 'alerts']);

    expect(Artisan::output())->toMatch('/Silences[ .]*ON \(3 day retention\)/');
});
