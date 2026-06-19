<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Alerts\Exceptions\InvalidCronExpression;
use RoundlyConsulting\Alerts\Support\CronSchedule;

afterEach(fn () => Carbon::setTestNow());

it('matches the every-minute wildcard', function () {
    Carbon::setTestNow('2023-03-22 12:50:00');

    expect((new CronSchedule('* * * * *'))->isDue())->toBeTrue();
});

it('evaluates hourly alias', function () {
    Carbon::setTestNow('2023-03-22 13:00:00');
    expect((new CronSchedule('@hourly'))->isDue())->toBeTrue();

    Carbon::setTestNow('2023-03-22 13:01:00');
    expect((new CronSchedule('@hourly'))->isDue())->toBeFalse();
});

it('evaluates daily alias only at midnight', function () {
    Carbon::setTestNow('2023-03-22 00:00:00');
    expect((new CronSchedule('@daily'))->isDue())->toBeTrue();

    Carbon::setTestNow('2023-03-22 01:00:00');
    expect((new CronSchedule('@daily'))->isDue())->toBeFalse();
});

it('honours step values', function () {
    Carbon::setTestNow('2023-03-22 12:10:00');
    expect((new CronSchedule('*/5 * * * *'))->isDue())->toBeTrue();

    Carbon::setTestNow('2023-03-22 12:12:00');
    expect((new CronSchedule('*/5 * * * *'))->isDue())->toBeFalse();
});

it('honours ranges and lists', function () {
    Carbon::setTestNow('2023-03-22 09:30:00'); // Wednesday

    expect((new CronSchedule('30 9-17 * * 1-5'))->isDue())->toBeTrue();

    Carbon::setTestNow('2023-03-25 09:30:00'); // Saturday
    expect((new CronSchedule('30 9-17 * * 1-5'))->isDue())->toBeFalse();
});

it('honours range with step', function () {
    Carbon::setTestNow('2023-03-22 12:04:00');
    expect((new CronSchedule('0-10/2 * * * *'))->isDue())->toBeTrue();

    Carbon::setTestNow('2023-03-22 12:05:00');
    expect((new CronSchedule('0-10/2 * * * *'))->isDue())->toBeFalse();
});

it('treats sunday as both 0 and 7', function () {
    Carbon::setTestNow('2023-03-26 00:00:00'); // Sunday

    expect((new CronSchedule('0 0 * * 7'))->isDue())->toBeTrue()
        ->and((new CronSchedule('0 0 * * 0'))->isDue())->toBeTrue();
});

it('evaluates weekly alias on sunday', function () {
    Carbon::setTestNow('2023-03-26 00:00:00'); // Sunday
    expect((new CronSchedule('@weekly'))->isDue())->toBeTrue();

    Carbon::setTestNow('2023-03-27 00:00:00'); // Monday
    expect((new CronSchedule('@weekly'))->isDue())->toBeFalse();
});

it('evaluates monthly and yearly aliases', function () {
    Carbon::setTestNow('2023-01-01 00:00:00');
    expect((new CronSchedule('@monthly'))->isDue())->toBeTrue()
        ->and((new CronSchedule('@yearly'))->isDue())->toBeTrue()
        ->and((new CronSchedule('@annually'))->isDue())->toBeTrue();

    Carbon::setTestNow('2023-02-01 00:00:00');
    expect((new CronSchedule('@yearly'))->isDue())->toBeFalse();
});

it('rejects expressions with the wrong field count', function () {
    new CronSchedule('* * *');
})->throws(InvalidCronExpression::class);

it('rejects out-of-range values', function () {
    new CronSchedule('99 * * * *');
})->throws(InvalidCronExpression::class);

it('rejects non-numeric values', function () {
    new CronSchedule('abc * * * *');
})->throws(InvalidCronExpression::class);

it('rejects an inverted range', function () {
    new CronSchedule('30-10 * * * *');
})->throws(InvalidCronExpression::class);

it('rejects a zero step', function () {
    new CronSchedule('*/0 * * * *');
})->throws(InvalidCronExpression::class);
