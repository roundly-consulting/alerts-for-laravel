<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Alerts\Checks\HttpPingCheck;
use RoundlyConsulting\Alerts\Enums\Status;

it('passes on the expected status', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    $result = HttpPingCheck::make('https://example.com')->check();

    expect($result->status)->toBe(Status::Ok)
        ->and($result->meta['status'])->toBe(200);
});

it('fails on an unexpected status', function () {
    Http::fake(['*' => Http::response('nope', 500)]);

    $result = HttpPingCheck::make('https://example.com')->expectStatus(200)->check();

    expect($result->status)->toBe(Status::Failed);
});

it('fails when the request throws', function () {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    $result = HttpPingCheck::make('https://example.com')->timeout(1)->check();

    expect($result->status)->toBe(Status::Failed)
        ->and($result->meta)->toHaveKey('error');
});

it('warns on a slow response', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    $result = HttpPingCheck::make('https://example.com')->slowerThan(-1)->check();

    expect($result->status)->toBe(Status::Warning);
});

it('uses a stable key', function () {
    expect(HttpPingCheck::make('https://example.com')->key())->toBe('http_ping_check');
});

it('builds a default notification', function () {
    expect(HttpPingCheck::make('https://example.com')->notification(new stdClass))
        ->toBeInstanceOf(RoundlyConsulting\Alerts\Notifications\HealthCheckFailedNotification::class);
});
