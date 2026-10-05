<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Support;

use Closure;
use RoundlyConsulting\Alerts\Exceptions\CheckTimedOut;
use Throwable;

/**
 * Runs a callback under a per-check time budget without any third-party vendor.
 *
 * Preferred path (CLI with ext-pcntl): a SIGALRM handler hard-aborts the callback
 * the moment the budget elapses. Fallback path (no pcntl, e.g. web SAPI / Windows):
 * best-effort — the callback runs to completion and is *reported* as timed out
 * afterwards if it overran. The fallback cannot abort, only report.
 */
final class Timeout
{
    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     *
     * @throws CheckTimedOut
     */
    public static function run(int $seconds, string $key, Closure $callback): mixed
    {
        if ($seconds <= 0) {
            return $callback();
        }

        if (self::supportsHardAbort()) {
            return self::runWithAlarm($seconds, $key, $callback);
        }

        return self::runBestEffort($seconds, $key, $callback);
    }

    public static function supportsHardAbort(): bool
    {
        return function_exists('pcntl_alarm')
            && function_exists('pcntl_signal')
            && function_exists('pcntl_async_signals');
    }

    /**
     * SIGALRM is process-wide, and a queue worker already uses it: it arms an alarm for
     * the job's own `--timeout`. So the pending alarm is taken over, not clobbered —
     * when it would fire first, it stays in charge (with its own handler) and the check
     * cannot extend it; otherwise the check's budget runs and the worker's alarm is
     * re-armed afterwards with whatever time it had left.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     *
     * @throws CheckTimedOut
     */
    private static function runWithAlarm(int $seconds, string $key, Closure $callback): mixed
    {
        pcntl_async_signals(true);

        $pending = pcntl_alarm(0);

        if ($pending > 0 && $pending <= $seconds) {
            pcntl_alarm($pending);

            return $callback();
        }

        $previous = pcntl_signal_get_handler(SIGALRM);
        $start = (int) hrtime(true);
        $timedOut = false;

        pcntl_signal(SIGALRM, function () use ($key, $seconds, &$timedOut): void {
            $timedOut = true;

            throw CheckTimedOut::after($key, $seconds);
        });

        try {
            pcntl_alarm($seconds);

            $result = $callback();
        } catch (Throwable $e) {
            // A check that caught the timeout and then failed some other way still timed out.
            throw $timedOut ? CheckTimedOut::after($key, $seconds) : $e;
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previous);

            if ($pending > 0) {
                $elapsed = (int) ceil(((int) hrtime(true) - $start) / 1_000_000_000);

                pcntl_alarm(max(1, $pending - $elapsed));
            }
        }

        // The alarm's exception is thrown INTO the check, so a broad `catch (Throwable)` in it
        // (every built-in check has one, and a host check may retry) can swallow it and return
        // normally. The budget was still overrun: report the timeout, not the check's result.
        if ($timedOut) {
            throw CheckTimedOut::after($key, $seconds);
        }

        return $result;
    }

    /**
     * Best-effort fallback for environments without ext-pcntl: the callback runs to
     * completion and is reported as timed out afterwards if it overran. It cannot
     * abort mid-flight, only report. Exposed for direct testing of the fallback.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     *
     * @throws CheckTimedOut
     */
    public static function runBestEffort(int $seconds, string $key, Closure $callback): mixed
    {
        $start = (int) hrtime(true);

        try {
            $result = $callback();
        } catch (Throwable $e) {
            self::throwIfOverran($start, $seconds, $key);

            throw $e;
        }

        self::throwIfOverran($start, $seconds, $key);

        return $result;
    }

    private static function throwIfOverran(int $start, int $seconds, string $key): void
    {
        $elapsedSeconds = ((int) hrtime(true) - $start) / 1_000_000_000;

        if ($elapsedSeconds > $seconds) {
            throw CheckTimedOut::after($key, $seconds);
        }
    }
}
