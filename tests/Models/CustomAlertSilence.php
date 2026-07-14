<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Tests\Models;

use RoundlyConsulting\Alerts\AlertSilence;

/**
 * A host's own silence model, the way `config/alerts.php` invites: extend the
 * packaged one and point `alerts.silence-model` at it.
 */
final class CustomAlertSilence extends AlertSilence
{
    protected $table = 'alert_silences';
}
