<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Tests\Models;

use Closure;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Alerts\Interfaces\HasNotifiablesForAlerts;
use RoundlyConsulting\Alerts\Traits\UsesHealthChecks;

/**
 * A notifiable that maps named escalation groups to users by an email prefix, e.g.
 * group "owner" -> users whose email starts with "owner".
 */
class GroupedTeam extends Model implements HasNotifiablesForAlerts
{
    use UsesHealthChecks;

    protected $table = 'teams';

    protected $guarded = [];

    public $timestamps = false;

    public function forEachNotifiableForAlerts(Closure $callback): void
    {
        User::query()->each(fn (User $user) => $callback($user));
    }

    /**
     * @return iterable<int, object>
     */
    public function notifiablesForAlertGroup(string $group): iterable
    {
        return User::query()
            ->where('email', 'like', $group.'%')
            ->get()
            ->all();
    }
}
