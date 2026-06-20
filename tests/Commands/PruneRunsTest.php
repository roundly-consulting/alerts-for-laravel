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

it('registers a daily prune on the scheduler when history is enabled', function () {
    $schedule = new Illuminate\Console\Scheduling\Schedule;

    (new RoundlyConsulting\Alerts\AlertsServiceProvider(app()))
        ->scheduleCommand($schedule);

    $commands = collect($schedule->events())
        ->map(fn ($event) => $event->command)
        ->filter()
        ->implode(' ');

    expect($commands)->toContain('alerts:prune-runs');
});

it('skips the prune schedule when history is disabled', function () {
    config()->set('alerts.history.enabled', false);

    $schedule = new Illuminate\Console\Scheduling\Schedule;

    (new RoundlyConsulting\Alerts\AlertsServiceProvider(app()))
        ->scheduleCommand($schedule);

    $commands = collect($schedule->events())
        ->map(fn ($event) => $event->command)
        ->filter()
        ->implode(' ');

    expect($commands)->not->toContain('alerts:prune-runs');
});
