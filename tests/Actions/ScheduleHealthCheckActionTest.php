<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Actions\ScheduleHealthCheckAction;
use RoundlyConsulting\Alerts\DataTransferObjects\ScheduleHealthCheckData;
use RoundlyConsulting\Alerts\Support\MonitorOptions;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;

it('persists a schedule with its options folded into meta', function (): void {
    $team = Team::create();

    $row = app(ScheduleHealthCheckAction::class)->execute($team, new ScheduleHealthCheckData(
        check: ExampleHealthCheck::class,
        frequency: 'hourly',
        failAfter: 2,
        tags: ['db'],
        meta: ['connection' => 'pgsql'],
    ));

    expect($row->exists)->toBeTrue()
        ->and($row->isScheduledFor($team))->toBeTrue()
        ->and($row->health_check)->toBe('example_health_check')
        ->and($row->frequency)->toBe('@hourly')
        ->and($row->tags)->toBe(['db'])
        ->and($row->meta)->toBe(['connection' => 'pgsql', MonitorOptions::FAIL_AFTER => 2]);
});

it('refuses to save a schedule whose cron it could never evaluate', function (): void {
    $team = Team::create();

    try {
        RoundlyConsulting\Alerts\Facades\Health::for($team)->monitor('example_health_check')->cron('0 9 * * FUNDAY')->save();
    } finally {
        expect(RoundlyConsulting\Alerts\HealthCheck::query()->count())->toBe(0);
    }
})->throws(RoundlyConsulting\Alerts\Exceptions\InvalidCronExpression::class);

it('saves a schedule on named weekdays', function (): void {
    $team = Team::create();

    $row = RoundlyConsulting\Alerts\Facades\Health::for($team)->monitor('example_health_check')->cron('0 9 * * MON-FRI')->save();

    expect($row->frequency)->toBe('0 9 * * MON-FRI');
});
