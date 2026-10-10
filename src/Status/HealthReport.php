<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Status;

use RoundlyConsulting\Alerts\Enums\Status;

final readonly class HealthReport
{
    /**
     * @param  list<CheckStatus>  $checks
     */
    public function __construct(
        public array $checks = [],
    ) {}

    /**
     * Read back what {@see self::toArray()} wrote. The stored top-level `status` is ignored and
     * recomputed; a payload it cannot read — or one whose checks it cannot all read — is null,
     * a cache miss.
     */
    public static function tryFromArray(mixed $payload): ?self
    {
        if (! is_array($payload) || ! is_array($payload['checks'] ?? null) || ! array_is_list($payload['checks'])) {
            return null;
        }

        $checks = [];

        foreach ($payload['checks'] as $row) {
            $check = CheckStatus::tryFromArray($row);

            if ($check === null) {
                return null;
            }

            $checks[] = $check;
        }

        return new self($checks);
    }

    /**
     * @return list<CheckStatus>
     */
    public function checks(): array
    {
        return $this->checks;
    }

    /**
     * A filtered copy keeping only checks carrying the given tag.
     */
    public function whereTag(string $tag): self
    {
        return new self(array_values(array_filter(
            $this->checks,
            fn (CheckStatus $check): bool => $check->hasAnyTag([$tag]),
        )));
    }

    /**
     * Worst-status roll-up across every check (Ok when there are none). A skipped check says
     * nothing about health, so it never outranks an ok one: an all-skipped report is ok.
     */
    public function overall(): Status
    {
        $overall = Status::Ok;

        foreach ($this->checks as $check) {
            if ($check->status !== Status::Skipped && $check->status->severity() > $overall->severity()) {
                $overall = $check->status;
            }
        }

        return $overall;
    }

    public function isHealthy(): bool
    {
        return $this->overall()->isAlertable() === false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->overall()->value,
            'checks' => array_map(fn (CheckStatus $check): array => $check->toArray(), $this->checks),
        ];
    }
}
