<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;

it('reports when no checks are registered', function () {
    $exit = Artisan::call('alerts:list');

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('No checks are registered.');
});

it('lists a registered check with its name and frequency', function () {
    Health::check(ExampleHealthCheck::class);

    $team = Team::create();
    $team->monitorCheck(ExampleHealthCheck::class)->hourly()->save();

    $exit = Artisan::call('alerts:list');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('example_health_check')
        ->and($output)->toContain('Every Hour');
});

it('shows a check as muted', function () {
    Health::check(ExampleHealthCheck::class);

    $team = Team::create();
    $team->monitorCheck(ExampleHealthCheck::class)->save();
    Health::silences()->mute('example_health_check');

    Artisan::call('alerts:list');

    // The muted check's row renders "yes" in the Muted? column.
    expect(Artisan::output())->toContain('| yes ');
});

it('filters the list by tag', function () {
    Health::check(ExampleHealthCheck::class);

    $team = Team::create();
    $team->monitorCheck(ExampleHealthCheck::class)->tags(['db'])->save();

    Artisan::call('alerts:list', ['--tag' => 'db']);
    expect(Artisan::output())->toContain('example_health_check');

    Artisan::call('alerts:list', ['--tag' => 'cache']);
    expect(Artisan::output())->toContain('No checks are registered.');
});

it('shows a dash for a registered but unscheduled check', function () {
    Health::check(ExampleHealthCheck::class);

    $exit = Artisan::call('alerts:list');

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('—');
});
