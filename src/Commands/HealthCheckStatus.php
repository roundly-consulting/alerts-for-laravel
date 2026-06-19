<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Alerts\Actions\BuildHealthReportAction;
use RoundlyConsulting\Alerts\Status\CheckStatus;

final class HealthCheckStatus extends Command
{
    protected $signature = 'alerts:status';

    protected $description = 'Show the current health status of every scheduled check';

    public function handle(BuildHealthReportAction $action): int
    {
        $report = $action->execute();

        if ($report->checks() === []) {
            $this->info('No health checks are scheduled.');

            return self::SUCCESS;
        }

        $this->table(
            ['Key', 'Name', 'Status', 'Last Alert', 'Message'],
            array_map(fn (CheckStatus $check): array => [
                $check->key,
                $check->name,
                $check->status->label(),
                $check->lastAlertAt?->toDateTimeString() ?? '—',
                $check->message ?? '—',
            ], $report->checks()),
        );

        $this->line('Overall: '.$report->overall()->label());

        return $report->overall()->isAlertable() ? self::FAILURE : self::SUCCESS;
    }
}
