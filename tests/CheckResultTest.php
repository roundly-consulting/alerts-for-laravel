<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\CheckResult;
use RoundlyConsulting\Alerts\Enums\Status;
use RuntimeException;

it('creates instance from ok static method', function () {
    $result = CheckResult::ok('Everything ok', ['meta' => true]);

    expect($result)
        ->message->toBe('Everything ok')
        ->meta->toBe(['meta' => true])
        ->status->toBe(Status::Ok)
        ->isOk->toBeTrue();
});

it('creates instance from failed static method', function () {
    $result = CheckResult::failed('Something wrong', ['error' => 500]);

    expect($result)
        ->message->toBe('Something wrong')
        ->meta->toBe(['error' => 500])
        ->status->toBe(Status::Failed)
        ->isOk->toBeFalse();
});

it('creates instance from warning static method', function () {
    $result = CheckResult::warning('Degraded');

    expect($result)
        ->status->toBe(Status::Warning)
        ->isOk->toBeFalse();
});

it('creates instance from skipped static method', function () {
    $result = CheckResult::skipped('Not now');

    expect($result)
        ->status->toBe(Status::Skipped)
        ->isOk->toBeFalse();
});

it('accepts a boolean for backward compatibility', function () {
    expect((new CheckResult(true))->status)->toBe(Status::Ok)
        ->and((new CheckResult(false))->status)->toBe(Status::Failed);
});

it('accepts a status enum directly', function () {
    expect((new CheckResult(Status::Warning))->status)->toBe(Status::Warning);
});

it('builds a failed result from an exception', function () {
    $result = CheckResult::fromException(new RuntimeException('boom'));

    expect($result->status)->toBe(Status::Failed)
        ->and($result->message)->toBe('boom')
        ->and($result->meta['exception'])->toBe(RuntimeException::class)
        ->and($result->meta['exception_message'])->toBe('boom')
        ->and($result->meta['exception_trace'])->toBeArray()
        ->and(count($result->meta['exception_trace']))->toBeLessThanOrEqual(CheckResult::TRACE_FRAMES);
});

it('overrides the message and merges meta when building from an exception', function () {
    $result = CheckResult::fromException(new RuntimeException('boom'), 'custom', ['extra' => 1]);

    expect($result->message)->toBe('custom')
        ->and($result->meta['extra'])->toBe(1)
        ->and($result->meta['exception'])->toBe(RuntimeException::class);
});
