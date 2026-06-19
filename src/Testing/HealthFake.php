<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Testing;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert as PHPUnit;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\CheckResult;
use RoundlyConsulting\Alerts\Health;

/**
 * Test double for the Health manager. Records run/alert/recover calls instead of
 * dispatching jobs or sending notifications, and exposes expressive assertions.
 */
final class HealthFake extends Health
{
    /** @var list<string> */
    private array $checked = [];

    /** @var list<string> */
    private array $alerted = [];

    /** @var list<string> */
    private array $recovered = [];

    public function run(string|Check $check, Model $notifiable): CheckResult
    {
        $check = $this->resolveCheck($check);
        $this->register($check);

        $key = $check->key();
        $result = $check->check();

        $this->checked[] = $key;

        if ($result->isOk === false && $result->status->isAlertable()) {
            $this->alerted[] = $key;
        }

        if ($result->isOk) {
            $this->recovered[] = $key;
        }

        return $result;
    }

    public function assertChecked(string $key): void
    {
        PHPUnit::assertContains($key, $this->checked, "The check [$key] was not run.");
    }

    public function assertAlerted(string $key): void
    {
        PHPUnit::assertContains($key, $this->alerted, "No alert was recorded for [$key].");
    }

    public function assertRecovered(string $key): void
    {
        PHPUnit::assertContains($key, $this->recovered, "No recovery was recorded for [$key].");
    }

    public function assertNothingAlerted(): void
    {
        PHPUnit::assertSame([], $this->alerted, 'Unexpected alerts were recorded.');
    }

    public function assertNothingRecovered(): void
    {
        PHPUnit::assertSame([], $this->recovered, 'Unexpected recoveries were recorded.');
    }
}
