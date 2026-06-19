<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Alerts\Checks\QueueCheck;
use RoundlyConsulting\Alerts\Enums\Status;

it('passes when the queue is small', function () {
    $connection = Mockery::mock(QueueContract::class);
    $connection->shouldReceive('size')->andReturn(2);
    Queue::shouldReceive('connection')->andReturn($connection);

    $result = QueueCheck::make()->maxSize(10)->check();

    expect($result->status)->toBe(Status::Ok)
        ->and($result->meta['size'])->toBe(2);
});

it('warns when the backlog exceeds the threshold', function () {
    $connection = Mockery::mock(QueueContract::class);
    $connection->shouldReceive('size')->andReturn(50);
    Queue::shouldReceive('connection')->andReturn($connection);

    $result = QueueCheck::make()->onQueue('emails')->maxSize(10)->check();

    expect($result->status)->toBe(Status::Warning);
});

it('fails when the queue connection cannot be resolved', function () {
    Queue::shouldReceive('connection')->andThrow(new RuntimeException('boom'));

    $result = QueueCheck::make('missing')->check();

    expect($result->status)->toBe(Status::Failed);
});

it('honours an explicit connection', function () {
    $queue = Mockery::mock(QueueContract::class);
    $queue->shouldReceive('size')->andReturn(0);
    Queue::shouldReceive('connection')->with('redis')->andReturn($queue);

    $result = QueueCheck::make()->connection('redis')->check();

    expect($result->status)->toBe(Status::Ok)
        ->and($result->meta['connection'])->toBe('redis');
});

it('builds a default notification', function () {
    expect(QueueCheck::make()->notification(new stdClass))
        ->toBeInstanceOf(RoundlyConsulting\Alerts\Notifications\HealthCheckFailedNotification::class);
});
