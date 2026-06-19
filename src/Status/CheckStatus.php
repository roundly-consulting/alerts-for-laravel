<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Status;

use Carbon\CarbonInterface;
use RoundlyConsulting\Alerts\Enums\Status;

final readonly class CheckStatus
{
    public function __construct(
        public string $key,
        public string $name,
        public Status $status,
        public ?CarbonInterface $lastAlertAt = null,
        public ?string $message = null,
    ) {}

    public function isOk(): bool
    {
        return $this->status === Status::Ok;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'status' => $this->status->value,
            'last_alert_at' => $this->lastAlertAt?->toIso8601String(),
            'message' => $this->message,
        ];
    }
}
