<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Enums\Status;

it('reports which statuses are alertable', function () {
    expect(Status::Ok->isAlertable())->toBeFalse()
        ->and(Status::Skipped->isAlertable())->toBeFalse()
        ->and(Status::Warning->isAlertable())->toBeTrue()
        ->and(Status::Failed->isAlertable())->toBeTrue();
});

it('orders severity from ok to failed', function () {
    expect(Status::Ok->severity())->toBe(0)
        ->and(Status::Skipped->severity())->toBe(1)
        ->and(Status::Warning->severity())->toBe(2)
        ->and(Status::Failed->severity())->toBe(3);
});

it('exposes translatable labels', function () {
    expect(Status::Ok->label())->toBe('OK')
        ->and(Status::Warning->label())->toBe('Warning')
        ->and(Status::Failed->label())->toBe('Failed')
        ->and(Status::Skipped->label())->toBe('Skipped');
});
