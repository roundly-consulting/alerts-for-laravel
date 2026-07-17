<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Tests\Models;

use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

/**
 * A host's own alert model, the way `config/alerts.php` invites: extend the packaged
 * one and point `alerts.alert` at it.
 */
final class CustomAlert extends Alert
{
    use CountsCreations;

    protected $table = 'alerts';
}
