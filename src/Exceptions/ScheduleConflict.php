<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Exceptions;

use RoundlyConsulting\Alerts\HealthCheck;
use RuntimeException;

/**
 * A reconfigure would give a schedule the identity another live schedule of the same check
 * holds (`Check::uniqueBy()`). Hosts map it to 409 Conflict. The message names the check, the
 * row and the meta keys of the identity — never their values, which can name a host.
 */
final class ScheduleConflict extends RuntimeException
{
    /**
     * @param  list<string>  $metaKeys
     */
    public static function for(HealthCheck $row, array $metaKeys): self
    {
        return new self(sprintf(
            'Health check [%s] #%s cannot take the identity another live schedule holds (unique by notifiable, check%s).',
            $row->health_check,
            (string) $row->getKey(),
            $metaKeys === [] ? '' : ' and meta '.implode(', ', $metaKeys),
        ));
    }
}
