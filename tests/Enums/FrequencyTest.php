<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Enums\Frequency;

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
