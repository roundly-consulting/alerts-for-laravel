<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Enums\Frequency;
use RoundlyConsulting\Enums\DataTransferObjects\EnumOption;

it('maps preset names to cron strings', function () {
    expect(Frequency::toCron('hourly'))->toBe('@hourly')
        ->and(Frequency::toCron('everyFiveMinutes'))->toBe('*/5 * * * *')
        ->and(Frequency::toCron('EveryMinute'))->toBe('* * * * *');
});

it('passes a raw cron string through unchanged', function () {
    expect(Frequency::toCron('15 3 * * *'))->toBe('15 3 * * *');
});

it('recognises a cron value matching a case', function () {
    expect(Frequency::toCron('@daily'))->toBe('@daily');
});

it('exposes the preset case names via the enums trait', function () {
    expect(Frequency::names()->all())->toContain('Hourly', 'Daily', 'EveryFiveMinutes');
});

it('backs options on the cron value keyed by the case name', function () {
    $option = Frequency::options()->firstOrFail();

    expect($option)->toBeInstanceOf(EnumOption::class)
        ->and($option->value)->toBe('* * * * *')
        ->and($option->name)->toBe('EveryMinute');
});

it('renders one option per case', function () {
    expect(Frequency::toOptions())->toHaveCount(Frequency::count())
        ->and(Frequency::count())->toBe(10);
});
