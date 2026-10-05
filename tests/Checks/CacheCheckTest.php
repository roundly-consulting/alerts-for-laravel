<?php

declare(strict_types=1);

use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
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
    $repository->shouldReceive('forget')->andReturnTrue();

    Cache::shouldReceive('store')->andReturn($repository);

    $result = CacheCheck::make()->check();

    expect($result->status)->toBe(Status::Failed)
        ->and($result->message)->toContain('unexpected value');
});

it('keeps overlapping runs from reading each other\'s sentinel', function () {
    $written = [];
    $inner = null;

    // A second run of the check lands between the first run's write and its read, as two
    // workers running the check for different notifiables against one shared store do.
    Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$written, &$inner): void {
        $written[] = $event->key;

        if ($inner === null) {
            $inner = false;
            $inner = CacheCheck::make('array')->check();
        }
    });

    $outer = CacheCheck::make('array')->check();

    expect($outer->status)->toBe(Status::Ok)
        ->and($inner?->status)->toBe(Status::Ok)
        ->and($written)->toHaveCount(2)
        ->and(collect($written)->filter(fn (string $key): bool => Cache::store('array')->has($key))->all())->toBe([]);
});
