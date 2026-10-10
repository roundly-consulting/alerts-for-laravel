<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\DataTransferObjects;

use RoundlyConsulting\Alerts\Enums\Frequency;
use RoundlyConsulting\Alerts\Exceptions\InvalidCronExpression;
use RoundlyConsulting\Alerts\Exceptions\InvalidHealthCheck;
use RoundlyConsulting\Alerts\Support\CronSchedule;
use RoundlyConsulting\Alerts\Support\MonitorOptions;

/**
 * The changes `Health::for($owner)->reconfigure($row, …)` merges into a schedule. Null
 * anywhere leaves that setting alone; `meta` merges key by key, a null value removing the key.
 */
final readonly class ReconfigureHealthCheckData
{
    /**
     * @param  string|null  $frequency  a cron expression or a preset name (`hourly`, `daily`, …)
     * @param  list<string>|null  $tags  `[]` clears them
     * @param  int|null  $failAfter  1 is the default and removes the setting
     * @param  int|null  $recoverAfter  1 is the default and removes the setting
     * @param  array<string, mixed>  $meta  merged into the row's meta; a null value removes the key
     *
     * @throws InvalidCronExpression for a frequency the scheduler could never evaluate
     * @throws InvalidHealthCheck for an escalation threshold below 1
     */
    public function __construct(
        public ?string $frequency = null,
        public ?int $maxAttempts = null,
        public ?int $decayMinutes = null,
        public ?array $tags = null,
        public ?int $failAfter = null,
        public ?int $recoverAfter = null,
        public array $meta = [],
    ) {
        $this->cronFrequency();

        $escalation = $meta[MonitorOptions::ESCALATION] ?? null;

        if (is_array($escalation)) {
            MonitorOptions::validateEscalation($escalation);
        }
    }

    /**
     * The cron expression to persist, a preset name resolved — null to leave it alone.
     *
     * @throws InvalidCronExpression
     */
    public function cronFrequency(): ?string
    {
        return $this->frequency === null ? null : CronSchedule::validate(Frequency::toCron($this->frequency));
    }
}
