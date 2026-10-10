<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Testing;

use Carbon\CarbonInterface;
use RoundlyConsulting\Alerts\CheckResult;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Status\CheckStatus;
use RoundlyConsulting\Alerts\Support\MonitorOptions;

/**
 * The in-memory stand-in for one monitor (a scheduled row, or a notifiable's on-demand row
 * for a check) inside `Health::fake()`:
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

    /** @var array<string, mixed>|null */
    private ?array $openMeta = null;

    /** @var list<array{status: Status, at: CarbonInterface}> */
    private array $results = [];

    /**
     * @param  list<string>  $tags
     * @param  HealthCheck  $row  the row the real manager would have written (unsaved)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $notifiable,
        private string $name,
        private array $tags,
        public readonly HealthCheck $row,
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
     * Start the consecutive-result counters over, as a changed schedule does; an open alert
     * stays open.
     */
    public function resetCounters(): void
    {
        $this->failures = 0;
        $this->successes = 0;
    }

    /**
     * Apply one run's result exactly as the pipeline would.
     *
     * @return bool|null true when an alert fired, false when a recovery was announced,
     *                   null when neither happened
     */
    public function apply(CheckResult $result, MonitorOptions $options, bool $muted): ?bool
    {
        // Stamped like a recorded run, so a report window (and `travel()`) applies.
        $this->results[] = ['status' => $result->status, 'at' => now()];

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

    /**
     * The report line: no p95 (the fake records no durations), uptime over the runs since
     * `$since`, null before any, and `$muted` as the silences decide it.
     */
    public function status(?CarbonInterface $since, bool $muted): CheckStatus
    {
        return new CheckStatus(
            key: $this->key,
            name: $this->name,
            status: $this->openStatus ?? Status::Ok,
            message: $this->openMessage,
            tags: $this->tags,
            uptime: $this->uptime($since),
            muted: $muted,
            meta: $this->openMeta,
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
        $this->openMeta = null;
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
        $this->openMeta = $muted ? [...$result->meta, MonitorOptions::MUTED => true] : $result->meta;

        if ($this->failures < $options->failAfter()) {
            return null;
        }

        return $muted ? null : true;
    }

    /**
     * The share of non-alertable runs since `$since`, null when there is none — as the real
     * report reads a row without runs in its window.
     */
    private function uptime(?CarbonInterface $since): ?float
    {
        $results = $since === null
            ? $this->results
            : array_filter($this->results, fn (array $result): bool => $result['at']->greaterThanOrEqualTo($since));

        if ($results === []) {
            return null;
        }

        $healthy = array_filter($results, fn (array $result): bool => ! $result['status']->isAlertable());

        return round((count($healthy) / count($results)) * 100, 2);
    }
}
