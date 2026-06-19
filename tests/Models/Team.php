<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Tests\Models;

use Closure;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Alerts\Interfaces\HasNotifiablesForAlerts;
use RoundlyConsulting\Alerts\Traits\UsesHealthChecks;

class Team extends Model implements HasNotifiablesForAlerts
{
    use UsesHealthChecks;

    protected $guarded = [];

    public $timestamps = false;

    public function forEachNotifiableForAlerts(Closure $callback): void
    {
        User::query()->each(fn (User $user) => $callback($user));
    }
}
