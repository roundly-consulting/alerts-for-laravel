<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Tests\Models;

use RoundlyConsulting\Alerts\HealthCheckRun;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

/**
 * A host's own run model, the way `config/alerts.php` invites: extend the packaged
 * one and point `alerts.history.model` at it.
 */
final class CustomHealthCheckRun extends HealthCheckRun
{
    use CountsCreations;

    protected $table = 'health_check_runs';
}
