<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\Status\CheckStatus;
use RoundlyConsulting\Alerts\Status\HealthReport;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;

it('persists tags onto the scheduled row via the builder', function () {
    $team = Team::create();

    $healthCheck = $team->monitorCheck(ExampleHealthCheck::class)
        ->tags(['critical', 'db'])
        ->save();

    expect($healthCheck->refresh()->tags)->toBe(['critical', 'db']);
});

it('scopes the report to a tag', function () {
    Health::check(ExampleHealthCheck::class);

    $team = Team::create();
    $team->monitorCheck(ExampleHealthCheck::class)->tags(['db'])->save();

    expect(Health::for($team)->report(['db'])->checks())->toHaveCount(1)
        ->and(Health::for($team)->report(['cache'])->checks())->toHaveCount(0);
});

it('filters an in-memory report by tag', function () {
    $report = new HealthReport([
        new CheckStatus('a', 'A', Status::Ok, tags: ['db']),
        new CheckStatus('b', 'B', Status::Ok, tags: ['cache']),
    ]);

    expect($report->whereTag('db')->checks())->toHaveCount(1)
        ->and($report->whereTag('db')->checks()[0]->key)->toBe('a');
});

it('merges check tags with row tags as effective tags', function () {
    Health::check(ExampleHealthCheck::class);

    $team = Team::create();
    $healthCheck = $team->monitorCheck(ExampleHealthCheck::class)->tags(['row-tag'])->save();

    expect($healthCheck->effectiveTags())->toContain('row-tag');
});

it('filters report, status, alerts:status and /health by the check\'s own tags', function () {
    Illuminate\Support\Facades\Notification::fake();

    Health::define('db-down', fn () => RoundlyConsulting\Alerts\CheckResult::failed('Database down'))
        ->tags(['critical']);
    Health::check(ExampleHealthCheck::class);

    $team = Team::create();
    // Neither schedule carries row tags: 'critical' lives on the check alone.
    Health::for($team)->run(Health::for($team)->monitor('db-down')->save());
    Health::for($team)->monitor(ExampleHealthCheck::class)->save();

    expect(Health::report(['critical'])->checks())->toHaveCount(1)
        ->and(Health::report(['critical'])->checks()[0]->key)->toBe('db-down')
        ->and(Health::status(['critical']))->toBe(Status::Failed)
        ->and(Health::for($team)->status(['critical']))->toBe(Status::Failed)
        ->and(Health::report(['nope'])->checks())->toBe([]);

    $this->artisan('alerts:status', ['--tag' => 'critical'])
        ->expectsOutputToContain('db-down')
        ->assertExitCode(1);

    Health::routes();

    $this->getJson('/health?tag=critical')
        ->assertStatus(503)
        ->assertJsonPath('checks.0.key', 'db-down');
});
