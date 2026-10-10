<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\CheckResult;
use RoundlyConsulting\Alerts\Exceptions\CheckTimedOut;
use Throwable;

/**
 * Runs a check the way every run path must: under its timeout, with any thrown
 * exception turned into a failed result instead of escaping into the caller — and reported
 * to the exception handler, since what is stored names only its class by default. A timeout
 * is the package's own condition, stored with its own text, and is not reported.
 *
 * @internal shared by the run pipeline and `Health::fake()`, so the fake can never
 *           treat a throwing or slow check differently from a real run
 */
final class SafeCheck
{
    public static function run(Check $check, ?int $timeout, string $key): CheckResult
    {
        try {
            return $timeout === null
                ? $check->check()
                : Timeout::run($timeout, $key, fn (): CheckResult => $check->check());
        } catch (CheckTimedOut $e) {
            return CheckResult::fromException($e, $e->getMessage(), [
                'timed_out_after' => $e->seconds,
            ]);
        } catch (Throwable $e) {
            self::report($e);

            return CheckResult::fromException($e);
        }
    }

    /**
     * Hand a throwable a check caught to the exception handler: its message and frames are not
     * stored by default, so the logs are their record. Used here and by the built-in checks for
     * the error they catch. A timeout thrown into a check is the package's own condition, stored
     * with its own text, so it is never reported.
     */
    public static function report(Throwable $e): void
    {
        if ($e instanceof CheckTimedOut) {
            return;
        }

        Container::getInstance()->make(ExceptionHandler::class)->report($e);
    }
}
