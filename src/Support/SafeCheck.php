<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Support;

use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\CheckResult;
use RoundlyConsulting\Alerts\Exceptions\CheckTimedOut;
use Throwable;

/**
 * Runs a check the way every run path must: under its timeout, with any thrown
 * exception turned into a failed result instead of escaping into the caller.
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
            return CheckResult::fromException($e);
        }
    }
}
