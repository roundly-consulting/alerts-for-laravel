<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\DataTransferObjects;

use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\Enums\Frequency;
use RoundlyConsulting\Alerts\Exceptions\InvalidCronExpression;
use RoundlyConsulting\Alerts\Exceptions\InvalidHealthCheck;
use RoundlyConsulting\Alerts\HealthManager;
use RoundlyConsulting\Alerts\Support\CronSchedule;
use RoundlyConsulting\Alerts\Support\MonitorOptions;

final readonly class ScheduleHealthCheckData
{
    /**
     * @param  class-string<Check>|string  $check  A Check class-string or its registered key.
     * @param  list<string>  $tags
     * @param  list<string>|null  $notifyVia
     * @param  array<int, list<string>>  $notifyViaLevels
     * @param  array<int, string>  $escalation  consecutive-failure threshold (at least 1) => group
     * @param  array<string, mixed>  $meta
     *
     * @throws InvalidHealthCheck for an escalation threshold below 1
     */
    public function __construct(
        public string $check,
        public string $frequency = '* * * * *',
        public int $maxAttempts = 1,
        public int $decayMinutes = 1,
        public int $failAfter = 1,
        public int $recoverAfter = 1,
        public ?int $timeout = null,
        public array $tags = [],
        public ?array $notifyVia = null,
        public array $notifyViaLevels = [],
        public array $escalation = [],
        public array $meta = [],
    ) {
        MonitorOptions::validateEscalation($escalation);
    }

    /**
     * The health-check key persisted on the HealthCheck row. A Check class-string is
     * resolved through the registry — the key of the instance registered for that class,
     * else of a fresh one — so it matches what `run()` uses; any other string is treated
     * as a key already.
     *
     * @throws InvalidHealthCheck for a class that is not a Check
     */
    public function key(): string
    {
        return app(HealthManager::class)->keyFor($this->check);
    }

    /**
     * The cron expression to persist: a preset name resolved to its expression, then
     * validated so an expression the scheduler could never evaluate is refused here.
     *
     * @throws InvalidCronExpression
     */
    public function cronFrequency(): string
    {
        return CronSchedule::validate(Frequency::toCron($this->frequency));
    }

    /**
     * Merge the declarative monitor options into the meta payload under their
     * reserved keys, keeping any consumer-provided meta.
     *
     * @return array<string, mixed>
     */
    public function metaWithOptions(): array
    {
        $meta = $this->meta;

        if ($this->failAfter > 1) {
            $meta[MonitorOptions::FAIL_AFTER] = $this->failAfter;
        }

        if ($this->recoverAfter > 1) {
            $meta[MonitorOptions::RECOVER_AFTER] = $this->recoverAfter;
        }

        if ($this->timeout !== null) {
            $meta[MonitorOptions::TIMEOUT] = $this->timeout;
        }

        if ($this->notifyVia !== null) {
            $meta[MonitorOptions::NOTIFY_VIA] = $this->notifyVia;
        }

        if ($this->notifyViaLevels !== []) {
            $meta[MonitorOptions::NOTIFY_VIA_LEVELS] = $this->notifyViaLevels;
        }

        if ($this->escalation !== []) {
            $meta[MonitorOptions::ESCALATION] = $this->escalation;
        }

        return $meta;
    }
}
