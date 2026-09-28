<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Testing;

use RoundlyConsulting\Alerts\CheckResult;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Status\CheckStatus;
use RoundlyConsulting\Alerts\Support\MonitorOptions;

/**
 * The in-memory stand-in for one monitor (a notifiable + a check) inside `Health::fake()`:
 * the consecutive-result counters and the open alert the real pipeline keeps in the
 * database, driven by the same gates — failAfter, recoverAfter and muting.
 *
 * @internal
 */
final class FakeMonitor
{
    private int $failures = 0;

    private int $successes = 0;

    private ?Status $openStatus = null;

    private ?string $openMessage = null;

    private bool $muted = false;

    /** @var list<CheckResult> */
    private array $results = [];

    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public readonly string $key,
        public readonly string $notifiable,
        private string $name,
        private array $tags,
    ) {}

    /**
     * @param  list<string>  $tags
     */
    public function describe(string $name, array $tags): void
    {
        $this->name = $name;
        $this->tags = $tags;
    }

    /**
     * Apply one run's result exactly as the pipeline would.
     *
     * @return bool|null true when an alert fired, false when a recovery was announced,
     *                   null when neither happened
     */
    public function apply(CheckResult $result, MonitorOptions $options, bool $muted): ?bool
    {
        $this->results[] = $result;

        if ($result->status === Status::Skipped) {
            return null;
        }

        return $result->isOk
            ? $this->succeed($options, $muted)
            : $this->fail($result, $options, $muted);
    }

    /**
     * @param  list<string>  $tags
     */
    public function hasAnyTag(array $tags): bool
    {
        return array_intersect($tags, $this->tags) !== [];
    }

    public function status(): CheckStatus
    {
        return new CheckStatus(
            key: $this->key,
            name: $this->name,
            status: $this->openStatus ?? Status::Ok,
            message: $this->openMessage,
            tags: $this->tags,
            uptime: $this->uptime(),
            muted: $this->openStatus !== null && $this->muted,
        );
    }

    private function succeed(MonitorOptions $options, bool $muted): ?bool
    {
        $this->successes++;
        $this->failures = 0;

        if ($this->openStatus === null || $this->successes < $options->recoverAfter()) {
            return null;
        }

        $this->openStatus = null;
        $this->openMessage = null;
        $this->muted = false;
        $this->successes = 0;

        return $muted ? null : false;
    }

    private function fail(CheckResult $result, MonitorOptions $options, bool $muted): ?bool
    {
        $this->failures++;
        $this->successes = 0;

        if ($this->openStatus === null && $this->failures < $options->failAfter()) {
            return null;
        }

        $this->openStatus = $result->status;
        $this->openMessage = $result->storedMessage();
        $this->muted = $muted;

        if ($this->failures < $options->failAfter()) {
            return null;
        }

        return $muted ? null : true;
    }

    /**
     * A monitor exists only once it has been run, so there is always a result.
     */
    private function uptime(): float
    {
        $healthy = array_filter($this->results, fn (CheckResult $result): bool => ! $result->status->isAlertable());

        return round((count($healthy) / count($this->results)) * 100, 2);
    }
}
