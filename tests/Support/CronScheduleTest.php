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

it('understands named weekdays and months', function (string $expression, string $at, bool $due) {
    Carbon::setTestNow($at);

    expect((new CronSchedule($expression))->isDue())->toBe($due);
})->with([
    'weekday range on a monday' => ['0 9 * * MON-FRI', '2026-09-28 09:00:00', true],
    'weekday range on a sunday' => ['0 9 * * MON-FRI', '2026-09-27 09:00:00', false],
    'lower-case list' => ['0 9 * * sat,sun', '2026-09-27 09:00:00', true],
    'named month' => ['0 0 1 JAN *', '2027-01-01 00:00:00', true],
    'named month elsewhere' => ['0 0 1 JAN *', '2026-10-01 00:00:00', false],
]);

it('rejects an unknown name', function () {
    new CronSchedule('0 9 * * FUNDAY');
})->throws(InvalidCronExpression::class);

it('validates an expression and hands back its canonical form', function () {
    expect(CronSchedule::validate('  */5 * * * *'))->toBe('*/5 * * * *');

    CronSchedule::validate('61 * * * *');
})->throws(InvalidCronExpression::class);

it('matches either day field when both day-of-month and day-of-week are restricted', function (string $expression, string $at, bool $due) {
    Carbon::setTestNow($at);

    expect((new CronSchedule($expression))->isDue())->toBe($due);
})->with([
    'weekday match off the listed days' => ['0 0 1,15 * MON', '2026-10-05 00:00:00', true],
    'listed day off the weekday' => ['0 0 1,15 * MON', '2026-10-01 00:00:00', true],
    'neither day field matches' => ['0 0 1,15 * MON', '2026-10-06 00:00:00', false],
    'single day-of-month or monday' => ['0 0 1 * MON', '2026-10-01 00:00:00', true],
    'unrestricted day-of-month keeps the weekday filter' => ['0 0 * * MON', '2026-10-01 00:00:00', false],
    'unrestricted day-of-week keeps the day filter' => ['0 0 15 * *', '2026-10-05 00:00:00', false],
]);

it('reads a stepped single value as stepping from that value to the end of the field', function (string $at, bool $due) {
    Carbon::setTestNow($at);

    expect((new CronSchedule('5/15 * * * *'))->isDue())->toBe($due);
})->with([
    'the start value' => ['2026-10-05 12:05:00', true],
    'one step later' => ['2026-10-05 12:20:00', true],
    'the last step' => ['2026-10-05 12:50:00', true],
    'between steps' => ['2026-10-05 12:21:00', false],
    'before the start value' => ['2026-10-05 12:00:00', false],
]);

it('rejects an empty list segment instead of reading it as every value', function (string $expression) {
    CronSchedule::validate($expression);
})->throws(InvalidCronExpression::class)->with([
    'trailing comma' => ['5, * * * *'],
    'lone comma' => [', * * * *'],
    'leading comma' => [',5 * * * *'],
    'step without a range' => ['/5 * * * *'],
]);

it('finds a due minute anywhere in a window, skipping what cannot match', function (string $expression, string $after, string $until, bool $due) {
    expect((new CronSchedule($expression))->isDueBetween(Carbon::parse($after), Carbon::parse($until)))->toBe($due);
})->with([
    'a minute inside the window' => ['7 * * * *', '2026-10-05 12:05:00', '2026-10-05 12:10:00', true],
    'the window end is included' => ['10 * * * *', '2026-10-05 12:05:00', '2026-10-05 12:10:00', true],
    'the window start is excluded' => ['5 * * * *', '2026-10-05 12:05:00', '2026-10-05 12:10:00', false],
    'a later hour of the same day' => ['0 18 * * *', '2026-10-05 12:00:00', '2026-10-05 23:59:00', true],
    'a later day' => ['0 0 * * MON', '2026-10-01 00:00:00', '2026-10-05 00:00:00', true],
    'a later month' => ['0 0 1 JAN *', '2026-02-01 00:00:00', '2027-01-01 00:00:00', true],
    'not yet in the month' => ['0 0 1 JAN *', '2026-02-01 00:00:00', '2026-12-31 23:59:00', false],
    'an impossible date' => ['0 0 31 2 *', '2026-01-01 00:00:00', '2028-12-31 23:59:00', false],
    'an empty window' => ['* * * * *', '2026-10-05 12:00:00', '2026-10-05 12:00:00', false],
]);
