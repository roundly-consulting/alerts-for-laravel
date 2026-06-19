<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Actions\BuildHealthReportAction;
use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\Status\CheckStatus;
use RoundlyConsulting\Alerts\Status\HealthReport;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;

it('rolls up the worst status', function () {
    $report = new HealthReport([
        new CheckStatus('a', 'A', Status::Ok),
        new CheckStatus('b', 'B', Status::Warning),
        new CheckStatus('c', 'C', Status::Failed),
    ]);

    expect($report->overall())->toBe(Status::Failed)
        ->and($report->isHealthy())->toBeFalse();
});

it('is healthy and ok when empty', function () {
    $report = new HealthReport;

    expect($report->overall())->toBe(Status::Ok)
        ->and($report->isHealthy())->toBeTrue()
        ->and($report->checks())->toBe([]);
});

it('serialises to an array', function () {
    $report = new HealthReport([
        new CheckStatus('a', 'A', Status::Warning, null, 'slow'),
    ]);

    expect($report->toArray())
        ->toHaveKey('status', 'warning')
        ->and($report->toArray()['checks'][0])->toHaveKey('key', 'a');
});

it('builds a report from open alerts', function () {
    Health::check(ExampleHealthCheck::class);

    $team = Team::create();
    $healthCheck = $team->createHealthCheck('example_health_check', '* * * * *');

    Alert::create([
        'notifiable_type' => $team->getMorphClass(),
        'notifiable_id' => $team->getKey(),
        'health_check_id' => $healthCheck->getKey(),
        'status' => Status::Warning,
        'message' => 'Degraded',
        'triggered_at' => now(),
    ]);

    $report = app(BuildHealthReportAction::class)->execute();

    expect($report->overall())->toBe(Status::Warning)
        ->and($report->checks())->toHaveCount(1)
        ->and($report->checks()[0]->name)->toBe('Example Health Check')
        ->and($report->checks()[0]->message)->toBe('Degraded');
});

it('reports ok for a check with no open alert', function () {
    Health::check(ExampleHealthCheck::class);

    $team = Team::create();
    $team->createHealthCheck('example_health_check', '* * * * *');

    $report = app(BuildHealthReportAction::class)->execute();

    expect($report->checks()[0]->status)->toBe(Status::Ok);
});

it('ignores recovered alerts', function () {
    Health::check(ExampleHealthCheck::class);

    $team = Team::create();
    $healthCheck = $team->createHealthCheck('example_health_check', '* * * * *');

    Alert::create([
        'notifiable_type' => $team->getMorphClass(),
        'notifiable_id' => $team->getKey(),
        'health_check_id' => $healthCheck->getKey(),
        'status' => Status::Failed,
        'triggered_at' => now(),
        'recovered_at' => now(),
    ]);

    expect(app(BuildHealthReportAction::class)->execute()->checks()[0]->status)->toBe(Status::Ok);
});

it('scopes the report to a notifiable', function () {
    Health::check(ExampleHealthCheck::class);

    $teamA = Team::create();
    $teamB = Team::create();
    $teamA->createHealthCheck('example_health_check', '* * * * *');
    $teamB->createHealthCheck('example_health_check', '* * * * *');

    $report = app(BuildHealthReportAction::class)->execute($teamA);

    expect($report->checks())->toHaveCount(1);
});

it('falls back to the key when the check is not registered', function () {
    $team = Team::create();
    $team->createHealthCheck('unknown_check', '* * * * *');

    $report = app(BuildHealthReportAction::class)->execute();

    expect($report->checks()[0]->name)->toBe('unknown_check');
});
