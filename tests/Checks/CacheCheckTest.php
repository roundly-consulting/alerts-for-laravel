<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Alerts\Actions\RunHealthCheckAction;
use RoundlyConsulting\Alerts\Checks\CacheCheck;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\Support\MonitorOptions;
use RoundlyConsulting\Alerts\Support\Timeout;
use RoundlyConsulting\Alerts\Tests\Models\Team;

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

it('reports a store that outlasts the monitor timeout as timed out, not unreachable', function () {
    Notification::fake();

    Cache::extend('slow', fn () => Cache::repository(new class extends ArrayStore
    {
        public function put($key, $value, $seconds)
        {
            sleep(3);

            return parent::put($key, $value, $seconds);
        }
    }));
    config()->set('cache.stores.slow', ['driver' => 'slow']);

    Health::check(CacheCheck::make('slow'));
    $healthCheck = createHealthCheckWithNotifiable(Team::create(), 'cache_check', meta: [MonitorOptions::TIMEOUT => 1]);

    $result = app(RunHealthCheckAction::class)->execute($healthCheck);

    expect($result->status)->toBe(Status::Failed)
        ->and($result->message)->toBe('Health check [cache_check] timed out after 1s.')
        ->and($result->meta['timed_out_after'] ?? null)->toBe(1);
})->skip(fn () => ! Timeout::supportsHardAbort(), 'ext-pcntl is not available.');
