<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\DataTransferObjects;

use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\Enums\Frequency;
use RoundlyConsulting\Alerts\Exceptions\InvalidHealthCheck;

final readonly class ScheduleHealthCheckData
{
    /**
     * @param  class-string<Check>|string  $check  A Check class-string or its registered key.
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $check,
        public string $frequency = '* * * * *',
        public int $maxAttempts = 1,
        public int $decayMinutes = 1,
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
}
