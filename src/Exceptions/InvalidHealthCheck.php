<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Exceptions;

use Exception;
use RoundlyConsulting\Alerts\Check;

final class InvalidHealthCheck extends Exception
{
    public static function doesntExtendBaseCheck(object $check): self
    {
        $checkClassName = $check::class;
        $baseCheckClass = Check::class;

        return new self("Class [$checkClassName] does not extend base check class [$baseCheckClass]");
    }

    public static function notRegistered(string $key): self
    {
        return new self("No health check is registered for key [$key]. Register it via Health::check().");
    }
}
