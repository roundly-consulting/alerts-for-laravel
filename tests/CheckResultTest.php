<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\CheckResult;
use RoundlyConsulting\Alerts\Enums\Status;

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
