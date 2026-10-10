<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\DataTransferObjects\ReconfigureHealthCheckData;
use RoundlyConsulting\Alerts\DataTransferObjects\ScheduleHealthCheckData;
use RoundlyConsulting\Alerts\Exceptions\ScheduleConflict;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Support\HealthCheckModel;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ServiceCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;

/**
 * `schedule()` of a `uniqueBy()` check looks the schedule up by identity, then inserts. Two
 * schedules of the same identity can both find nothing: the unique `identity_hash` index lets
 * one insert win, and the other updates the winner's row — the last write wins. The
 * competitor runs, and commits, between this schedule's lookup and its insert.
 */
beforeEach(function (): void {
    Health::check(ServiceCheck::class);
    $this->team = Team::create();
});

it('keeps one schedule when two schedules of the same identity race', function (bool $inTransaction): void {
    $interleaved = false;
    $connection = HealthCheckModel::query()->getConnection();

    $race = function () use (&$interleaved, $connection): HealthCheck {
        $connection->beforeStartingTransaction(function () use (&$interleaved): void {
            if ($interleaved) {
                return;
            }

            $interleaved = true;

            Health::for($this->team)->schedule(new ScheduleHealthCheckData(check: ServiceCheck::class, frequency: 'hourly', meta: ['service' => 'api']));
        });

        return Health::for($this->team)->schedule(new ScheduleHealthCheckData(check: ServiceCheck::class, frequency: 'daily', meta: ['service' => 'api']));
    };

    // Inside the caller's transaction the lost insert must roll back to its own savepoint —
    // on postgres a failed statement otherwise aborts the whole transaction.
    $row = $inTransaction ? $connection->transaction($race) : $race();

    expect($interleaved)->toBeTrue()
        ->and(HealthCheck::query()->count())->toBe(1)
        ->and($row->getKey())->toBe(HealthCheck::query()->sole()->getKey())
        ->and(HealthCheck::query()->sole()->frequency)->toBe('@daily');
})->with(['bare' => false, 'inside a transaction' => true]);

it('raises a conflict when another schedule takes the identity a reconfigure moves to', function (): void {
    $row = Health::for($this->team)->schedule(new ScheduleHealthCheckData(check: ServiceCheck::class, meta: ['service' => 'api']));
    $interleaved = false;

    HealthCheckModel::query()->getConnection()->beforeStartingTransaction(function () use (&$interleaved): void {
        if ($interleaved) {
            return;
        }

        $interleaved = true;

        Health::for($this->team)->schedule(new ScheduleHealthCheckData(check: ServiceCheck::class, meta: ['service' => 'web']));
    });

    expect(fn () => Health::for($this->team)->reconfigure($row, new ReconfigureHealthCheckData(meta: ['service' => 'web'])))
        ->toThrow(ScheduleConflict::class);

    expect($interleaved)->toBeTrue()
        ->and($row->fresh()?->meta)->toBe(['service' => 'api'])
        ->and(HealthCheck::query()->count())->toBe(2);
});

it('adopts a legacy schedule once when two schedules race for it', function (): void {
    $legacy = fn (): HealthCheck => HealthCheck::create([
        'notifiable_type' => $this->team->getMorphClass(),
        'notifiable_id' => $this->team->getKey(),
        'health_check' => 'service_check',
        'frequency' => '* * * * *',
        'meta' => ['service' => 'api'],
    ]);

    $oldest = $legacy();
    $stacked = $legacy();
    $interleaved = false;

    // The competitor adopts the oldest row while this schedule is about to: this one then
    // finds it taken, fails to give the identity to the stacked copy, and updates the winner.
    HealthCheckModel::query()->getConnection()->beforeStartingTransaction(function () use (&$interleaved): void {
        if ($interleaved) {
            return;
        }

        $interleaved = true;

        Health::for($this->team)->schedule(new ScheduleHealthCheckData(check: ServiceCheck::class, frequency: 'hourly', meta: ['service' => 'api']));
    });

    $row = Health::for($this->team)->schedule(new ScheduleHealthCheckData(check: ServiceCheck::class, frequency: 'daily', meta: ['service' => 'api']));

    expect($interleaved)->toBeTrue()
        ->and($row->getKey())->toBe($oldest->getKey())
        ->and($oldest->fresh()?->frequency)->toBe('@daily')
        ->and($oldest->fresh()?->identity_hash)->not->toBeNull()
        ->and($stacked->fresh()?->identity_hash)->toBeNull()
        ->and(HealthCheck::query()->count())->toBe(2);
});
