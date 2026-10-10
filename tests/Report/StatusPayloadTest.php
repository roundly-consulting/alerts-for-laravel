<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Status\CheckStatus;
use RoundlyConsulting\Alerts\Status\HealthReport;

/**
 * The roll-up, and the cache round-trip: a host that runs with `cache.serializable_classes`
 * off caches `toArray()` and reads it back with `tryFromArray()` — a payload it cannot read
 * is a cache miss (null), never a TypeError.
 */
it('leaves skipped checks out of the roll-up', function (array $statuses, Status $overall): void {
    $report = new HealthReport(array_map(
        fn (Status $status): CheckStatus => new CheckStatus($status->value, $status->label(), $status),
        $statuses,
    ));

    expect($report->overall())->toBe($overall);
})->with([
    'ok and skipped' => [[Status::Ok, Status::Skipped], Status::Ok],
    'only skipped' => [[Status::Skipped, Status::Skipped], Status::Ok],
    'skipped and warning' => [[Status::Skipped, Status::Warning], Status::Warning],
    'warning and failed' => [[Status::Warning, Status::Skipped, Status::Failed], Status::Failed],
]);

function fullCheckStatus(): CheckStatus
{
    return new CheckStatus(
        key: 'db',
        name: 'Database',
        status: Status::Failed,
        lastAlertAt: CarbonImmutable::parse('2026-10-10 12:00:00'),
        message: 'down',
        tags: ['infra', 'db'],
        uptime: 99.5,
        p95LatencyMs: 120,
        muted: true,
        meta: ['exception' => RuntimeException::class, 'nested' => ['a' => 1]],
    );
}

it('serialises the meta with the rest of the check', function (): void {
    expect(fullCheckStatus()->toArray())->toBe([
        'key' => 'db',
        'name' => 'Database',
        'status' => 'failed',
        'last_alert_at' => '2026-10-10T12:00:00+00:00',
        'message' => 'down',
        'tags' => ['infra', 'db'],
        'uptime' => 99.5,
        'p95_latency_ms' => 120,
        'muted' => true,
        'meta' => ['exception' => RuntimeException::class, 'nested' => ['a' => 1]],
    ]);
});

it('reads a check status back from its array, through JSON too', function (): void {
    $array = fullCheckStatus()->toArray();

    $direct = CheckStatus::tryFromArray($array);
    $viaJson = CheckStatus::tryFromArray(json_decode((string) json_encode($array), true));

    expect($direct?->toArray())->toBe($array)
        ->and($viaJson?->toArray())->toBe($array)
        ->and($direct?->lastAlertAt)->toBeInstanceOf(CarbonImmutable::class);
});

it('reads a minimal check status with its defaults', function (): void {
    $status = CheckStatus::tryFromArray(['key' => 'db', 'name' => 'Database', 'status' => 'ok', 'muted' => false]);

    expect($status?->toArray())->toBe((new CheckStatus('db', 'Database', Status::Ok))->toArray());
});

it('reads an integer uptime as a float', function (): void {
    $status = CheckStatus::tryFromArray([...fullCheckStatus()->toArray(), 'uptime' => 100]);

    expect($status?->uptime)->toBe(100.0);
});

it('treats a check payload it cannot read as a miss', function (mixed $payload): void {
    expect(CheckStatus::tryFromArray($payload))->toBeNull();
})->with([
    'not an array' => ['db'],
    'missing key' => [['name' => 'Database', 'status' => 'ok', 'muted' => false]],
    'blank key' => [['key' => '', 'name' => 'Database', 'status' => 'ok', 'muted' => false]],
    'missing name' => [['key' => 'db', 'status' => 'ok', 'muted' => false]],
    'unknown status' => [['key' => 'db', 'name' => 'Database', 'status' => 'broken', 'muted' => false]],
    'missing muted' => [['key' => 'db', 'name' => 'Database', 'status' => 'ok']],
    'string muted' => [['key' => 'db', 'name' => 'Database', 'status' => 'ok', 'muted' => 'yes']],
    'bad time' => [['key' => 'db', 'name' => 'Database', 'status' => 'ok', 'muted' => false, 'last_alert_at' => 'yesterday']],
    'bad tags' => [['key' => 'db', 'name' => 'Database', 'status' => 'ok', 'muted' => false, 'tags' => [1]]],
    'tags not a list' => [['key' => 'db', 'name' => 'Database', 'status' => 'ok', 'muted' => false, 'tags' => ['a' => 'db']]],
    'time not a string' => [['key' => 'db', 'name' => 'Database', 'status' => 'ok', 'muted' => false, 'last_alert_at' => 1760097600]],
    'bad message' => [['key' => 'db', 'name' => 'Database', 'status' => 'ok', 'muted' => false, 'message' => 5]],
    'bad uptime' => [['key' => 'db', 'name' => 'Database', 'status' => 'ok', 'muted' => false, 'uptime' => '99']],
    'bad p95' => [['key' => 'db', 'name' => 'Database', 'status' => 'ok', 'muted' => false, 'p95_latency_ms' => 1.5]],
    'bad meta' => [['key' => 'db', 'name' => 'Database', 'status' => 'ok', 'muted' => false, 'meta' => 'x']],
]);

it('reads a report back from its array, recomputing the overall status', function (): void {
    $report = new HealthReport([
        fullCheckStatus(),
        new CheckStatus('cache', 'Cache', Status::Skipped),
    ]);

    $array = $report->toArray();
    $read = HealthReport::tryFromArray([...$array, 'status' => 'ok']);

    expect($read?->toArray())->toBe($array)
        ->and($read?->overall())->toBe(Status::Failed)
        ->and(HealthReport::tryFromArray(['checks' => []])?->checks())->toBe([]);
});

it('treats a report payload it cannot read as a miss', function (mixed $payload): void {
    expect(HealthReport::tryFromArray($payload))->toBeNull();
})->with([
    'not an array' => [null],
    'missing checks' => [['status' => 'ok']],
    'checks not a list' => [['checks' => 'none']],
    'one unreadable check' => [['checks' => [['key' => 'db', 'name' => 'Database', 'status' => 'ok', 'muted' => false], ['key' => 'x']]]],
]);
