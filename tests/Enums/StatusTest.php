<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Enums\DataTransferObjects\EnumOption;
use RoundlyConsulting\Enums\Exceptions\EnumException;

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

it('exposes readable labels via the enums trait', function () {
    expect(Status::Ok->label())->toBe('Ok')
        ->and(Status::Warning->label())->toBe('Warning')
        ->and(Status::Failed->label())->toBe('Failed')
        ->and(Status::Skipped->label())->toBe('Skipped');
});

it('aliases readable() to label()', function () {
    foreach (Status::cases() as $status) {
        expect($status->readable())->toBe($status->label());
    }
});

it('lists the backed values in declaration order', function () {
    expect(Status::values()->all())->toBe(['ok', 'warning', 'failed', 'skipped']);
});

it('lists the readable labels', function () {
    expect(Status::labels()->all())->toBe(['Ok', 'Warning', 'Failed', 'Skipped']);
});

it('maps values to labels for select inputs', function () {
    expect(Status::toOptions()->all())->toBe([
        'ok' => 'Ok',
        'warning' => 'Warning',
        'failed' => 'Failed',
        'skipped' => 'Skipped',
    ]);
});

it('builds a validation rule from the backed values', function () {
    expect(Status::validationRule())->toBe('in:ok,warning,failed,skipped');
});

it('exposes option DTOs with value, label and name', function () {
    $option = Status::options()->firstOrFail();

    expect($option)->toBeInstanceOf(EnumOption::class)
        ->and($option->value)->toBe('ok')
        ->and($option->label)->toBe('Ok')
        ->and($option->name)->toBe('Ok');
});

it('resolves a case by value and reports value presence', function () {
    expect(Status::from('failed'))->toBe(Status::Failed)
        ->and(Status::hasValue('failed'))->toBeTrue()
        ->and(Status::hasValue('nope'))->toBeFalse();
});

it('resolves a case by its readable label', function () {
    expect(Status::tryFromLabel('Warning'))->toBe(Status::Warning)
        ->and(Status::tryFromLabel('Nope'))->toBeNull();
});

it('throws when resolving an unknown name or label', function () {
    expect(fn () => Status::fromName('bogus'))->toThrow(EnumException::class);
    expect(fn () => Status::fromLabel('bogus'))->toThrow(EnumException::class);
});
