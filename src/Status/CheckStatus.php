<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Status;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RoundlyConsulting\Alerts\Enums\Status;
use Throwable;

/**
 * One row of a health report. `uptime` and `p95LatencyMs` are null when the row has no run in
 * the report's window; `muted` says whether a silence in force covers the row; `meta` is the
 * open alert's raw meta — it can hold exception text, so sanitise it before showing it to
 * users.
 */
final readonly class CheckStatus
{
    /**
     * @param  list<string>  $tags
     * @param  array<array-key, mixed>|null  $meta  the open alert's raw meta, null without an open alert
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
        public ?array $meta = null,
    ) {}

    /**
     * Read back what {@see self::toArray()} wrote — from a cache, say, that stores arrays only.
     * Anything it cannot read (a missing key, name, status or muted flag, a value of the wrong
     * type) is null: a cache miss, never an error.
     */
    public static function tryFromArray(mixed $row): ?self
    {
        if (! is_array($row)) {
            return null;
        }

        $key = $row['key'] ?? null;
        $name = $row['name'] ?? null;
        $status = is_string($row['status'] ?? null) ? Status::tryFrom($row['status']) : null;
        $muted = $row['muted'] ?? null;

        if (! is_string($key) || $key === '' || ! is_string($name) || $status === null || ! is_bool($muted)) {
            return null;
        }

        $message = $row['message'] ?? null;
        $tags = self::tags($row['tags'] ?? []);
        $uptime = $row['uptime'] ?? null;
        $p95 = $row['p95_latency_ms'] ?? null;
        $meta = $row['meta'] ?? null;
        $lastAlertAt = self::moment($row['last_alert_at'] ?? null);

        if (($message !== null && ! is_string($message))
            || $tags === false
            || ($uptime !== null && ! is_int($uptime) && ! is_float($uptime))
            || ($p95 !== null && ! is_int($p95))
            || ($meta !== null && ! is_array($meta))
            || $lastAlertAt === false) {
            return null;
        }

        return new self(
            key: $key,
            name: $name,
            status: $status,
            lastAlertAt: $lastAlertAt,
            message: $message,
            tags: $tags,
            uptime: $uptime === null ? null : (float) $uptime,
            p95LatencyMs: $p95,
            muted: $muted,
            meta: $meta,
        );
    }

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
            'meta' => $this->meta,
        ];
    }

    /**
     * A list of strings, or false for anything else.
     *
     * @return list<string>|false
     */
    private static function tags(mixed $value): array|false
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        $tags = [];

        foreach ($value as $tag) {
            if (! is_string($tag)) {
                return false;
            }

            $tags[] = $tag;
        }

        return $tags;
    }

    /**
     * The ISO 8601 moment `toArray()` writes, null for none, false for anything else.
     */
    private static function moment(mixed $value): CarbonImmutable|false|null
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            return false;
        }

        try {
            return CarbonImmutable::createFromFormat(CarbonInterface::ATOM, $value) ?: false;
        } catch (Throwable) {
            return false;
        }
    }
}
