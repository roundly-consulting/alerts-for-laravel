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
        // Unmonitored after this job was queued. SerializesModels restores the row without
        // the soft-delete scope, so it comes back trashed rather than missing — and a run
        // would record history, open an alert and notify for a check nobody monitors.
        if ($this->healthCheck->trashed()) {
            return;
        }

        $action->execute($this->healthCheck);
    }
}
