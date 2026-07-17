<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Tests\Models;

use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

/**
 * A host's own monitor model, the way `config/alerts.php` invites: extend the
 * packaged one and point `alerts.health-check` at it.
 */
final class CustomHealthCheck extends HealthCheck
{
    use CountsCreations;

    protected $table = 'health_checks';
}
