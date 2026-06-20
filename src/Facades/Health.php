<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Facades;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Alerts\AlertSilence;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\CheckResult;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Status\HealthReport;
use RoundlyConsulting\Alerts\Support\PendingCheck;
use RoundlyConsulting\Alerts\Testing\HealthFake;

/**
 * @method static \RoundlyConsulting\Alerts\Health checks(array<int, string|object> $checks)
 * @method static \RoundlyConsulting\Alerts\Health check(string|object $healthCheck)
 * @method static PendingCheck define(string $key, Closure $callback)
 * @method static \RoundlyConsulting\Alerts\Health register(Check $check)
 * @method static Collection<string, Check> all()
 * @method static Check|null find(string $key)
 * @method static CheckResult run(string|Check $check, Model $notifiable)
 * @method static HealthReport report(Model|null $notifiable = null, list<string>|null $tags = null)
 * @method static Status status(Model|null $notifiable = null)
 * @method static AlertSilence mute(string $key, CarbonInterface|null $until = null, Model|null $notifiable = null, string|null $reason = null)
 * @method static void unmute(string $key, Model|null $notifiable = null)
 * @method static bool isMuted(string $key, Model|null $notifiable = null)
 * @method static Route routes(string|null $uri = null)
 * @method static HealthFake fake()
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
