<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Alerts\Checks\StorageCheck;
use RoundlyConsulting\Alerts\Enums\Status;

beforeEach(fn () => Storage::fake('local'));

it('passes when free space is comfortably above the minimum', function () {
    $result = StorageCheck::make('local')->minimumBytes(1)->check();

    expect($result->status)->toBe(Status::Ok)
        ->and($result->meta)->toHaveKey('free_bytes');
});

it('warns when free space dips below the warning threshold', function () {
    $result = StorageCheck::make('local')
        ->minimumBytes(1)
        ->warnBytes(PHP_INT_MAX)
        ->check();

    expect($result->status)->toBe(Status::Warning);
});

it('fails when free space is below the minimum', function () {
    $result = StorageCheck::make('local')->minimumBytes(PHP_INT_MAX)->check();

    expect($result->status)->toBe(Status::Failed);
});

it('fails when the disk path cannot be read', function () {
    Storage::shouldReceive('disk->path')->andThrow(new RuntimeException('no disk'));

    $result = StorageCheck::make('broken')->check();

    expect($result->status)->toBe(Status::Failed);
});

it('warns using the default warning threshold', function () {
    // free space sits between the minimum and twice the minimum.
    $free = (int) disk_free_space(Storage::disk('local')->path(''));

    $result = StorageCheck::make('local')->minimumBytes((int) ($free * 0.75))->check();

    expect($result->status)->toBe(Status::Warning);
});

it('builds a default notification', function () {
    expect(StorageCheck::make('local')->notification(new stdClass))
        ->toBeInstanceOf(RoundlyConsulting\Alerts\Notifications\HealthCheckFailedNotification::class);
});
