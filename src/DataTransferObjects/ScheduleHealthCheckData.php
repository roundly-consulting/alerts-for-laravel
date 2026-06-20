<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\DataTransferObjects;

use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\Enums\Frequency;
use RoundlyConsulting\Alerts\Exceptions\InvalidHealthCheck;
use RoundlyConsulting\Alerts\Support\MonitorOptions;

final readonly class ScheduleHealthCheckData
{
    /**
     * @param  class-string<Check>|string  $check  A Check class-string or its registered key.
     * @param  list<string>  $tags
     * @param  list<string>|null  $notifyVia
     * @param  array<int, list<string>>  $notifyViaLevels
     * @param  array<int, string>  $escalation
     * @param  array<string, mixed>  $meta
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
    ) {}

    /**
     * The health-check key persisted on the HealthCheck row. A Check class-string
     * is resolved to its key(); any other string is treated as a key already.
     */
    public function key(): string
    {
        if (is_subclass_of($this->check, Check::class)) {
            return (new $this->check)->key();
        }

        if (class_exists($this->check)) {
            throw InvalidHealthCheck::doesntExtendBaseCheck(new $this->check);
        }

        return $this->check;
    }

    public function cronFrequency(): string
    {
        return Frequency::toCron($this->frequency);
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
