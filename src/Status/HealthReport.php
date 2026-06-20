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
     * Worst-status roll-up across every check (Ok when there are none).
     */
    public function overall(): Status
    {
        $overall = Status::Ok;

        foreach ($this->checks as $check) {
            if ($check->status->severity() > $overall->severity()) {
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
