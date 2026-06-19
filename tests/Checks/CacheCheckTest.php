<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use RoundlyConsulting\Alerts\Checks\CacheCheck;
use RoundlyConsulting\Alerts\Enums\Status;

it('passes when the cache round-trips a value', function () {
    $result = CacheCheck::make()->check();

    expect($result->status)->toBe(Status::Ok);
});

it('respects an explicit store', function () {
    $result = CacheCheck::make()->store('array')->check();

    expect($result->status)->toBe(Status::Ok)
        ->and($result->meta['store'])->toBe('array');
});

it('fails when the store cannot be resolved', function () {
    $result = CacheCheck::make('missing-store')->check();

    expect($result->status)->toBe(Status::Failed);
});

it('builds a default notification', function () {
    expect(CacheCheck::make()->notification(new stdClass))
        ->toBeInstanceOf(RoundlyConsulting\Alerts\Notifications\HealthCheckFailedNotification::class);
});

it('fails when the value read back does not match', function () {
    $repository = Mockery::mock(Repository::class);
    $repository->shouldReceive('put')->andReturnTrue();
    $repository->shouldReceive('get')->andReturn('different');

    Cache::shouldReceive('store')->andReturn($repository);

    $result = CacheCheck::make()->check();

    expect($result->status)->toBe(Status::Failed);
});
