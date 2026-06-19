<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RoundlyConsulting\Alerts\Actions\RunHealthCheckAction;
use RoundlyConsulting\Alerts\HealthCheck;

final class HealthCheckJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public HealthCheck $healthCheck,
    ) {}

    public function handle(RunHealthCheckAction $action): void
    {
        $action->execute($this->healthCheck);
    }
}
