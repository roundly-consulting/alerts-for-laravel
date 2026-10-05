<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\HealthCheckRun;
use RoundlyConsulting\Alerts\Tests\Models\Team;

it('prunes only runs older than the given days', function () {
    $team = Team::create();
    $healthCheck = createHealthCheckWithNotifiable($team);

    HealthCheckRun::factory()->create([
        'health_check_id' => $healthCheck->getKey(),
        'ran_at' => now()->subDays(10),
    ]);
    HealthCheckRun::factory()->create([
        'health_check_id' => $healthCheck->getKey(),
        'ran_at' => now()->subDay(),
    ]);

    $this->artisan('alerts:prune-runs', ['--days' => 7])
        ->assertExitCode(0);

    expect(HealthCheckRun::count())->toBe(1);
});

it('falls back to the configured retention when no days option is given', function () {
    config()->set('alerts.history.retention_days', 5);

    $team = Team::create();
    $healthCheck = createHealthCheckWithNotifiable($team);

    HealthCheckRun::factory()->create([
        'health_check_id' => $healthCheck->getKey(),
        'ran_at' => now()->subDays(6),
    ]);

    $this->artisan('alerts:prune-runs')->assertExitCode(0);

    expect(HealthCheckRun::count())->toBe(0);
});

function bootedScheduleCommands(): string
{
    app()->forgetInstance(Illuminate\Console\Scheduling\Schedule::class);
    app()->singleton(Illuminate\Console\Scheduling\Schedule::class, fn () => new Illuminate\Console\Scheduling\Schedule);

    $provider = new RoundlyConsulting\Alerts\AlertsServiceProvider(app());
    $provider->register();
    $provider->boot();

    return collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->map(fn ($event) => $event->command)
        ->filter()
        ->implode(' ');
}

it('registers a daily prune on the scheduler when history is enabled', function () {
    $schedule = new Illuminate\Console\Scheduling\Schedule;

    (new RoundlyConsulting\Alerts\AlertsServiceProvider(app()))->schedulePrune($schedule);

    expect($schedule->events()[0]->command)->toContain('alerts:prune-runs')
        ->and($schedule->events()[0]->expression)->toBe('0 0 * * *')
        ->and(bootedScheduleCommands())->toContain('alerts:prune-runs');
});

it('skips the prune schedule when history is disabled', function () {
    config()->set('alerts.history.enabled', false);

    expect(bootedScheduleCommands())->not->toContain('alerts:prune-runs')
        ->toContain('alerts:perform-health-checks');
});

it('schedules pruning even when the perform command is wired by hand', function () {
    config()->set('alerts.schedule.enabled', false);
    config()->set('alerts.history.enabled', true);

    expect(bootedScheduleCommands())->toContain('alerts:prune-runs')
        ->not->toContain('alerts:perform-health-checks');
});

it('refuses a retention that is not a whole number of at least one day and deletes nothing', function (string $days) {
    $healthCheck = createHealthCheckWithNotifiable(Team::create());

    foreach ([now()->subMinute(), now()->subDays(2), now()->subDays(40)] as $ranAt) {
        HealthCheckRun::factory()->create(['health_check_id' => $healthCheck->getKey(), 'ran_at' => $ranAt]);
    }

    $this->artisan('alerts:prune-runs', ['--days' => $days])
        ->expectsOutputToContain('--days must be a whole number of days, at least 1')
        ->assertFailed();

    expect(HealthCheckRun::count())->toBe(3);
})->with(['zero' => '0', 'negative' => '-1', 'word' => 'abc', 'decimal' => '1.5']);
