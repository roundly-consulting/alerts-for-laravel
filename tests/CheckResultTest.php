<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\CheckResult;

it('creates instance from ok static method', function () {
    $result = CheckResult::ok('Everything ok', ['meta' => true]);

    expect($result)
        ->message->toBe('Everything ok')
        ->meta->toBe(['meta' => true])
        ->isOk->toBeTrue();
});

it('creates instance from failed static method', function () {
    $result = CheckResult::failed('Something wrong', ['error' => 500]);

    expect($result)
        ->message->toBe('Something wrong')
        ->meta->toBe(['error' => 500])
        ->isOk->toBeFalse();
});
