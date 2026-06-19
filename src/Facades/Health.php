<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Facades;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Alerts\Check;

/**
 * @method static \RoundlyConsulting\Alerts\Health checks(array<int, string|object> $checks)
 * @method static \RoundlyConsulting\Alerts\Health check(string|object $healthCheck)
 * @method static Collection<string, Check> all()
 * @method static Check|null find(string $key)
 *
 * @see \RoundlyConsulting\Alerts\Health
 */
final class Health extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'health';
    }
}
