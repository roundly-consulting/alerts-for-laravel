<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts;

use Illuminate\Support\Collection;
use RoundlyConsulting\Alerts\Exceptions\InvalidHealthCheck;

final class Health
{
    /**
     * @var array<string, Check>
     */
    protected array $checks = [];

    /**
     * @param  array<int, string|object>  $checks
     */
    public function checks(array $checks): self
    {
        foreach ($checks as $check) {
            $this->check($check);
        }

        return $this;
    }

    public function check(string|object $healthCheck): self
    {
        if (is_string($healthCheck)) {
            $healthCheck = new $healthCheck;
        }

        if (! $healthCheck instanceof Check) {
            throw InvalidHealthCheck::doesntExtendBaseCheck($healthCheck);
        }

        $this->checks[$healthCheck->key()] = $healthCheck;

        return $this;
    }

    /**
     * @return Collection<string, Check>
     */
    public function all(): Collection
    {
        return collect($this->checks);
    }

    public function find(string $key): ?Check
    {
        return $this->all()->firstWhere(fn (Check $healthCheck): bool => $healthCheck->key() === $key);
    }
}
