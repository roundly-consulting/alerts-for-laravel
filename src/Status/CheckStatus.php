<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Status;

use Carbon\CarbonInterface;
use RoundlyConsulting\Alerts\Enums\Status;

final readonly class CheckStatus
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public string $key,
        public string $name,
        public Status $status,
        public ?CarbonInterface $lastAlertAt = null,
        public ?string $message = null,
        public array $tags = [],
        public ?float $uptime = null,
        public ?int $p95LatencyMs = null,
        public bool $muted = false,
    ) {}

    public function isOk(): bool
    {
        return $this->status === Status::Ok;
    }

    /**
     * @param  list<string>  $tags
     */
    public function hasAnyTag(array $tags): bool
    {
        return array_intersect($tags, $this->tags) !== [];
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
            'tags' => $this->tags,
            'uptime' => $this->uptime,
            'p95_latency_ms' => $this->p95LatencyMs,
            'muted' => $this->muted,
        ];
    }
}
