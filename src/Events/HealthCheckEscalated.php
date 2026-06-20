<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use RoundlyConsulting\Alerts\Alert;

final class HealthCheckEscalated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Alert $alert,
        public int $fromLevel,
        public int $toLevel,
    ) {}
}
