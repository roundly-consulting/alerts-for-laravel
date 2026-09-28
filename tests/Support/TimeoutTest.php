<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Exceptions\CheckTimedOut;
use RoundlyConsulting\Alerts\Support\Timeout;

it('returns the callback result when no budget is given', function () {
    expect(Timeout::run(0, 'k', fn () => 'value'))->toBe('value');
});

it('hard-aborts a slow callback under pcntl', function () {
    if (! Timeout::supportsHardAbort()) {
        $this->markTestSkipped('ext-pcntl is not available.');
    }

    expect(fn () => Timeout::run(1, 'slow', function () {
        sleep(3);

        return 'never';
    }))->toThrow(CheckTimedOut::class);
})->skip(fn () => ! Timeout::supportsHardAbort(), 'ext-pcntl is not available.');

it('returns and clears the alarm for a fast callback under pcntl', function () {
    if (! Timeout::supportsHardAbort()) {
        $this->markTestSkipped('ext-pcntl is not available.');
    }

    expect(Timeout::run(5, 'fast', fn () => 'ok'))->toBe('ok')
        ->and(pcntl_alarm(0))->toBe(0);
})->skip(fn () => ! Timeout::supportsHardAbort(), 'ext-pcntl is not available.');

it('downgrades to a timeout in the best-effort fallback when the budget is exceeded', function () {
    expect(fn () => Timeout::runBestEffort(0, 'slow', function () {
        usleep(2000);

        return 'done';
    }))->toThrow(CheckTimedOut::class);
});

it('returns normally in the best-effort fallback within budget', function () {
    expect(Timeout::runBestEffort(60, 'fast', fn () => 'done'))->toBe('done');
});

it('reports a timeout in the fallback even when the callback itself throws after overrunning', function () {
    expect(fn () => Timeout::runBestEffort(0, 'slow', function () {
        usleep(2000);

        throw new RuntimeException('inner');
    }))->toThrow(CheckTimedOut::class);
});

it('carries the key and seconds on the timeout exception', function () {
    $exception = CheckTimedOut::after('database_check', 7);

    expect($exception->key)->toBe('database_check')
        ->and($exception->seconds)->toBe(7);
});

it('re-arms a queue worker\'s pending job timeout after a check timeout', function () {
    $worker = function (): void {};
    pcntl_signal(SIGALRM, $worker);
    pcntl_alarm(100);

    try {
        expect(Timeout::run(5, 'fast', fn () => 'ok'))->toBe('ok');

        $handler = pcntl_signal_get_handler(SIGALRM);
        $remaining = pcntl_alarm(0);
    } finally {
        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);
    }

    expect($handler)->toBe($worker)
        ->and($remaining)->toBeGreaterThanOrEqual(98)
        ->and($remaining)->toBeLessThanOrEqual(100);
})->skip(fn () => ! Timeout::supportsHardAbort(), 'ext-pcntl is not available.');

it('leaves a sooner worker deadline in charge of the check', function () {
    $worker = function (): void {};
    pcntl_signal(SIGALRM, $worker);
    pcntl_alarm(3);

    try {
        [$inside, $handlerInside] = Timeout::run(10, 'long', function (): array {
            $armed = pcntl_alarm(0);
            pcntl_alarm($armed);

            return [$armed, pcntl_signal_get_handler(SIGALRM)];
        });

        $after = pcntl_alarm(0);
    } finally {
        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);
    }

    // The check's longer budget must not extend the worker's own timeout.
    expect($inside)->toBeLessThanOrEqual(3)
        ->and($inside)->toBeGreaterThan(0)
        ->and($handlerInside)->toBe($worker)
        ->and($after)->toBeLessThanOrEqual(3)
        ->and($after)->toBeGreaterThan(0);
})->skip(fn () => ! Timeout::supportsHardAbort(), 'ext-pcntl is not available.');
