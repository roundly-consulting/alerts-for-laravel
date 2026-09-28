<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Facades;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\HealthManager;
use RoundlyConsulting\Alerts\Status\HealthReport;
use RoundlyConsulting\Alerts\Support\NotifiableHealth;
use RoundlyConsulting\Alerts\Support\PendingCheck;
use RoundlyConsulting\Alerts\Support\Silences;
use RoundlyConsulting\Alerts\Testing\HealthFake;

/**
 * @method static HealthManager checks(array<int, string|object> $checks)
 * @method static HealthManager check(string|object $healthCheck)
 * @method static PendingCheck define(string $key, Closure $callback)
 * @method static Collection<string, Check> all()
 * @method static Check|null find(string $key)
 * @method static NotifiableHealth for(Model $notifiable)
 * @method static HealthReport report(list<string>|null $tags = null)
 * @method static Status status(list<string>|null $tags = null)
 * @method static Silences silences()
 * @method static int runDue()
 * @method static int prune(int|null $days = null)
 * @method static Route routes(string|null $uri = null)
 *
 * @see HealthManager
 */
final class Health extends Facade
{
    /**
     * Swap the manager for a recording fake that keeps the registered checks.
     */
    public static function fake(): HealthFake
    {
        $manager = self::getFacadeRoot();

        $fake = new HealthFake(
            self::getFacadeApplication() ?? app(),
            $manager instanceof HealthManager ? $manager->all()->all() : [],
        );

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return HealthManager::class;
    }
}
