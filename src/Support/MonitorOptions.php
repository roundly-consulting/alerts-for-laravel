<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Support;

use RoundlyConsulting\Alerts\Exceptions\InvalidHealthCheck;

/**
 * Typed accessor over the reserved per-monitor declaration keys stored inside a
 * HealthCheck row's `meta` json. Keeps the schema small while keeping the option
 * lookups disciplined and discoverable.
 */
final readonly class MonitorOptions
{
    public const string FAIL_AFTER = 'fail_after';

    public const string RECOVER_AFTER = 'recover_after';

    public const string TIMEOUT = 'timeout';

    public const string NOTIFY_VIA = 'notify_via';

    public const string NOTIFY_VIA_LEVELS = 'notify_via_levels';

    public const string ESCALATION = 'escalation';

    public const string MUTED = 'muted';

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<int|string, string>  $defaultEscalation  the globally configured
     *                                                        policy, applied when the row declares none
     */
    public function __construct(
        private array $meta = [],
        private array $defaultEscalation = [],
    ) {}

    /**
     * Refuse an escalation policy with a threshold that can never be reached: the level
     * is the consecutive-failure count, and a failing run has at least one, so a group
     * keyed 0 — or a list-style policy (`['owner', 'team']`, keyed 0 and 1) — is never paged.
     *
     * @template TPolicy of array<array-key, mixed>
     *
     * @param  TPolicy  $policy
     * @return TPolicy
     *
     * @throws InvalidHealthCheck
     */
    public static function validateEscalation(array $policy): array
    {
        foreach (array_keys($policy) as $threshold) {
            if (! is_int($threshold) || $threshold < 1) {
                throw InvalidHealthCheck::invalidEscalationThreshold($threshold);
            }
        }

        return $policy;
    }

    /**
     * @param  array<string, mixed>|null  $meta
     * @param  array<int|string, string>  $defaultEscalation
     */
    public static function fromMeta(?array $meta, array $defaultEscalation = []): self
    {
        return new self($meta ?? [], $defaultEscalation);
    }

    public function failAfter(): int
    {
        return max(1, (int) ($this->meta[self::FAIL_AFTER] ?? 1));
    }

    public function recoverAfter(): int
    {
        return max(1, (int) ($this->meta[self::RECOVER_AFTER] ?? 1));
    }

    public function timeout(): ?int
    {
        $timeout = $this->meta[self::TIMEOUT] ?? null;

        return $timeout === null ? null : (int) $timeout;
    }

    /**
     * @return list<string>|null
     */
    public function notifyVia(): ?array
    {
        $channels = $this->meta[self::NOTIFY_VIA] ?? null;

        if (! is_array($channels) || $channels === []) {
            return null;
        }

        return array_values(array_map('strval', $channels));
    }

    /**
     * @return array<int, list<string>>
     */
    public function notifyViaLevels(): array
    {
        $levels = $this->meta[self::NOTIFY_VIA_LEVELS] ?? [];

        if (! is_array($levels)) {
            return [];
        }

        $resolved = [];

        foreach ($levels as $level => $channels) {
            if (is_array($channels)) {
                $resolved[(int) $level] = array_values(array_map('strval', $channels));
            }
        }

        return $resolved;
    }

    /**
     * The effective channel list for a given escalation level, applying the
     * documented fallback order: level override -> global list -> default.
     *
     * @param  list<string>  $default
     * @return list<string>
     */
    public function channelsForLevel(int $level, array $default): array
    {
        return $this->notifyViaLevels()[$level] ?? $this->notifyVia() ?? $default;
    }

    /**
     * Escalation policy as a threshold => group map, sorted ascending by threshold.
     *
     * A policy declared on the row wins; a row that declares none inherits the
     * globally configured default (`alerts.escalation`).
     *
     * @return array<int, string>
     */
    public function escalation(): array
    {
        $declared = $this->meta[self::ESCALATION] ?? [];

        $policy = is_array($declared) && $declared !== []
            ? $declared
            : $this->defaultEscalation;

        $resolved = [];

        foreach ($policy as $threshold => $group) {
            $resolved[(int) $threshold] = (string) $group;
        }

        ksort($resolved);

        return $resolved;
    }

    /**
     * The highest declared escalation threshold that is <= the given failure count,
     * or 0 when none applies.
     */
    public function levelForFailures(int $consecutiveFailures): int
    {
        $level = 0;

        foreach ($this->escalation() as $threshold => $group) {
            if ($threshold <= $consecutiveFailures) {
                $level = $threshold;
            }
        }

        return $level;
    }

    /**
     * The escalation groups for every newly reached level in (fromLevel, toLevel].
     *
     * @return list<string>
     */
    public function groupsBetween(int $fromLevel, int $toLevel): array
    {
        $groups = [];

        foreach ($this->escalation() as $threshold => $group) {
            if ($threshold > $fromLevel && $threshold <= $toLevel) {
                $groups[] = $group;
            }
        }

        return $groups;
    }
}
