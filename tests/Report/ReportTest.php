<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\HealthCheckRun;
use RoundlyConsulting\Alerts\HealthManager;
use RoundlyConsulting\Alerts\Status\CheckStatus;
use RoundlyConsulting\Alerts\Support\MonitorOptions;
use RoundlyConsulting\Alerts\Support\Percentile;
use RoundlyConsulting\Alerts\Tests\HealthChecks\AnotherHealthCheck;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;

/**
 * `Health::report()` reads every row, its open alert, its run statistics and the silences in
 * a fixed number of queries — whatever the number of rows or the retained history — over an
 * optional window.
 */
beforeEach(function (): void {
    Carbon::setTestNow('2026-10-10 12:00:00');
    Health::check(ExampleHealthCheck::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function reportRow(?Team $team = null, ?string $frequency = '* * * * *', array $tags = []): HealthCheck
{
    $team ??= Team::create();

    return HealthCheck::create([
        'notifiable_type' => $team->getMorphClass(),
        'notifiable_id' => $team->getKey(),
        'health_check' => 'example_health_check',
        'frequency' => $frequency,
        'tags' => $tags === [] ? null : $tags,
        'meta' => [],
    ]);
}

/**
 * @param  list<array{0: Status, 1: int, 2?: Carbon}>  $runs  status, duration, ran at
 */
function reportRuns(HealthCheck $row, array $runs): void
{
    foreach ($runs as $run) {
        HealthCheckRun::factory()->create([
            'health_check_id' => $row->getKey(),
            'status' => $run[0],
            'duration_ms' => $run[1],
            'ran_at' => $run[2] ?? now(),
        ]);
    }
}

function openReportAlert(HealthCheck $row, Status $status = Status::Failed, array $meta = []): Alert
{
    return Alert::create([
        'notifiable_type' => $row->notifiable_type,
        'notifiable_id' => $row->notifiable_id,
        'health_check_id' => $row->getKey(),
        'status' => $status,
        'message' => 'down',
        'meta' => $meta,
        'triggered_at' => now(),
        'open_slot' => Alert::OPEN_SLOT,
    ]);
}

function reportQueryCount(Closure $report): int
{
    DB::enableQueryLog();
    DB::flushQueryLog();

    $report();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('builds the report in the same few queries for 1, 10 or 50 rows', function (): void {
    $counts = [];

    foreach ([1, 10, 50] as $n) {
        HealthCheck::query()->forceDelete();

        foreach (range(1, $n) as $i) {
            $row = reportRow();
            reportRuns($row, [[Status::Ok, 10], [Status::Failed, 20]]);

            if ($i % 2 === 0) {
                openReportAlert($row);
            }
        }

        Health::silences()->mute('cache');

        $counts[$n] = reportQueryCount(fn () => expect(Health::report()->checks())->toHaveCount($n));
    }

    // rows, open alerts, run aggregate, p95, silences.
    expect($counts)->toBe([1 => 5, 10 => 5, 50 => 5]);
});

it('reads the rows in id order', function (): void {
    Health::check(AnotherHealthCheck::class);
    $team = Team::create();

    foreach (['another_health_check', 'example_health_check', 'another_health_check'] as $key) {
        HealthCheck::create([
            'notifiable_type' => $team->getMorphClass(),
            'notifiable_id' => $team->getKey(),
            'health_check' => $key,
            'frequency' => '* * * * *',
        ]);
    }

    expect(array_map(fn (CheckStatus $check): string => $check->key, Health::report()->checks()))
        ->toBe(['another_health_check', 'example_health_check', 'another_health_check']);
});

it('computes uptime and p95 over the whole history, or only since a moment', function (): void {
    $row = reportRow();

    reportRuns($row, [
        [Status::Failed, 900, now()->subDays(3)],
        [Status::Failed, 800, now()->subDays(2)],
        [Status::Ok, 100, now()->subHours(5)],
        [Status::Skipped, 50, now()->subHours(2)],
        [Status::Warning, 200, now()->subHour()],
    ]);

    $all = Health::report()->checks()[0];
    $day = Health::report(since: now()->subDay())->checks()[0];

    expect($all->uptime)->toBe(40.0)
        ->and($all->p95LatencyMs)->toBe(900)
        ->and($day->uptime)->toBe(66.67)
        ->and($day->p95LatencyMs)->toBe(200)
        ->and(Health::for(Team::query()->findOrFail($row->notifiable_id))->report(since: now()->subMinutes(30))->checks()[0]->uptime)->toBeNull();
});

it('reports no uptime or p95 for a row without runs in the window', function (): void {
    $never = reportRow();
    $old = reportRow();
    reportRuns($old, [[Status::Ok, 10, now()->subWeek()]]);

    $report = Health::report(since: now()->subDay())->checks();

    expect($report[0]->uptime)->toBeNull()
        ->and($report[0]->p95LatencyMs)->toBeNull()
        ->and($report[1]->uptime)->toBeNull()
        ->and($report[1]->p95LatencyMs)->toBeNull()
        ->and(Health::report()->checks()[0]->uptime)->toBeNull()
        ->and(Health::report()->checks()[1]->uptime)->toBe(100.0);
});

it('reports no uptime or p95 while run history is off', function (): void {
    reportRuns(reportRow(), [[Status::Ok, 10]]);

    config()->set('alerts.history.enabled', false);

    $check = Health::report()->checks()[0];

    expect($check->uptime)->toBeNull()
        ->and($check->p95LatencyMs)->toBeNull();
});

it('reads the nearest-rank p95 the percentile helper defines', function (int $runs): void {
    $row = reportRow();
    $durations = array_map(fn (int $i): int => ($i * 37) % 1000 + $i, range(1, $runs));
    shuffle($durations);

    reportRuns($row, array_map(fn (int $ms): array => [Status::Ok, $ms], $durations));

    expect(Health::report()->checks()[0]->p95LatencyMs)
        ->toBe(Percentile::nearestRank($durations, 95))
        ->toBe($row->p95LatencyMs());
})->with([1, 2, 20, 21, 100]);

it('flags a row muted by the silences, open alert or not', function (Closure $silence, bool $muted): void {
    Health::define('tagged', fn (): bool => true)->tags(['infra']);
    $team = Team::create();
    $row = reportRow($team, tags: ['db']);
    $tagged = HealthCheck::create([
        'notifiable_type' => $team->getMorphClass(),
        'notifiable_id' => $team->getKey(),
        'health_check' => 'tagged',
        'frequency' => '* * * * *',
        'meta' => [],
    ]);

    $silence($team);

    $checks = Health::report()->checks();

    expect($checks[0]->muted)->toBe($muted)
        ->and($checks[1]->muted)->toBe($muted);
})->with([
    'global, no open alert' => [fn () => Health::silences()->mute('*'), true],
    'tags: a row tag and a check tag' => [function (): void {
        Health::silences()->mute('db');
        Health::silences()->mute('infra');
    }, true],
    'own notifiable' => [fn (Team $team) => Health::silences()->mute('*', for: $team), true],
    'another notifiable' => [fn () => Health::silences()->mute('*', for: Team::create()), false],
    'switched off' => [function (): void {
        Health::silences()->mute('*');
        config()->set('alerts.silence', false);
    }, false],
]);

it('stops flagging an alert opened under a mute once the mute is lifted', function (): void {
    $row = reportRow();
    $alert = openReportAlert($row, meta: [MonitorOptions::MUTED => true]);

    $check = Health::report()->checks()[0];

    expect($check->muted)->toBeFalse()
        ->and($check->meta)->toBe([MonitorOptions::MUTED => true])
        ->and($check->status)->toBe(Status::Failed)
        ->and($check->message)->toBe('down')
        ->and($check->lastAlertAt?->toDateTimeString())->toBe($alert->triggered_at->toDateTimeString());
});

it('carries the open alert\'s meta and nothing for a healthy row', function (): void {
    $failing = reportRow();
    $healthy = reportRow();
    openReportAlert($failing, meta: ['exception' => RuntimeException::class, 'service' => 'api']);

    $checks = Health::report()->checks();

    // Key by key: jsonb (and MySQL JSON) reorder object keys.
    expect($checks[0]->meta)->toHaveCount(2)
        ->toMatchArray(['exception' => RuntimeException::class, 'service' => 'api'])
        ->and($checks[0]->toArray()['meta'])->toBe($checks[0]->meta)
        ->and($checks[1]->meta)->toBeNull()
        ->and($checks[1]->status)->toBe(Status::Ok);
});

it('reads the newest open alert of a row, never one of another notifiable', function (): void {
    $row = reportRow();

    Alert::create([
        'notifiable_type' => $row->notifiable_type,
        'notifiable_id' => $row->notifiable_id,
        'health_check_id' => $row->getKey(),
        'status' => Status::Warning,
        'triggered_at' => now(),
    ]);
    openReportAlert($row, Status::Failed);

    $stray = reportRow();
    Alert::create([
        'notifiable_type' => $stray->notifiable_type,
        'notifiable_id' => (int) $stray->notifiable_id + 1000,
        'health_check_id' => $stray->getKey(),
        'status' => Status::Failed,
        'triggered_at' => now(),
    ]);

    $checks = Health::report()->checks();

    expect($checks[0]->status)->toBe(Status::Failed)
        ->and($checks[1]->status)->toBe(Status::Ok);
});

it('drops on-demand rows from a scheduled-only report', function (): void {
    $team = Team::create();
    reportRow($team);
    reportRow($team, frequency: null);

    expect(Health::report()->checks())->toHaveCount(2)
        ->and(Health::report(scheduledOnly: true)->checks())->toHaveCount(1)
        ->and(Health::for($team)->report(scheduledOnly: true)->checks())->toHaveCount(1)
        ->and(app(HealthManager::class)->report(['nope'], null, true)->checks())->toBe([]);
});

it('reports more rows than one id batch holds', function (): void {
    $team = Team::create();
    $now = now()->toDateTimeString();

    foreach (array_chunk(range(1, 1001), 250) as $chunk) {
        HealthCheck::query()->insert(array_map(fn (): array => [
            'notifiable_type' => $team->getMorphClass(),
            'notifiable_id' => $team->getKey(),
            'health_check' => 'example_health_check',
            'frequency' => '* * * * *',
            'created_at' => $now,
            'updated_at' => $now,
        ], $chunk));
    }

    $last = HealthCheck::query()->latest('id')->firstOrFail();
    reportRuns($last, [[Status::Ok, 10], [Status::Failed, 30]]);
    openReportAlert($last);

    $checks = Health::report()->checks();

    expect($checks)->toHaveCount(1001)
        ->and($checks[1000]->uptime)->toBe(50.0)
        ->and($checks[1000]->p95LatencyMs)->toBe(30)
        ->and($checks[1000]->status)->toBe(Status::Failed)
        ->and($checks[0]->uptime)->toBeNull();
});

it('counts exactly the statuses that never alert towards uptime', function (): void {
    // The report's run aggregate counts `ok` and `skipped` as healthy, the statuses
    // HealthCheck::uptimePercentage() keeps; a new non-alertable status must be added there.
    expect(array_values(array_filter(Status::cases(), fn (Status $status): bool => ! $status->isAlertable())))
        ->toBe([Status::Ok, Status::Skipped]);
});
