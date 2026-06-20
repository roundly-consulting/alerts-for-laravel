<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Checks;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\CheckResult;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Notifications\HealthCheckFailedNotification;
use Throwable;

/**
 * Pings an HTTP endpoint, failing on a non-expected status or timeout and warning
 * when the response is slower than a latency budget.
 */
final class HttpPingCheck extends Check
{
    private int $timeout = 5;

    private int $expectedStatus = 200;

    private ?int $slowThresholdMs = null;

    public function __construct(
        private readonly string $url = '',
        ?HealthCheck $healthCheck = null,
    ) {
        parent::__construct($healthCheck);
    }

    public static function make(string $url): self
    {
        return new self($url);
    }

    public function timeout(int $seconds): self
    {
        $this->timeout = $seconds;

        return $this;
    }

    public function expectStatus(int $status): self
    {
        $this->expectedStatus = $status;

        return $this;
    }

    public function slowerThan(int $milliseconds): self
    {
        $this->slowThresholdMs = $milliseconds;

        return $this;
    }

    public function key(): string
    {
        return 'http_ping_check';
    }

    public function check(): CheckResult
    {
        $start = microtime(true);

        try {
            $response = Http::timeout($this->timeout)->get($this->url);
        } catch (Throwable $e) {
            return CheckResult::failed(
                (string) __('alerts::checks.http_unreachable', ['url' => $this->url]),
                ['url' => $this->url, 'error' => $e->getMessage()],
            );
        }

        $elapsedMs = (int) round((microtime(true) - $start) * 1000);
        $meta = ['url' => $this->url, 'status' => $response->status(), 'latency_ms' => $elapsedMs];

        if ($response->status() !== $this->expectedStatus) {
            return CheckResult::failed(
                (string) __('alerts::checks.http_bad_status', [
                    'url' => $this->url,
                    'status' => $response->status(),
                ]),
                $meta,
            );
        }

        if ($this->slowThresholdMs !== null && $elapsedMs > $this->slowThresholdMs) {
            return CheckResult::warning(
                (string) __('alerts::checks.http_slow', ['url' => $this->url, 'latency' => $elapsedMs]),
                $meta,
            );
        }

        return CheckResult::ok(
            (string) __('alerts::checks.http_ok', ['url' => $this->url]),
            $meta,
        );
    }

    public function notification(object $notifiable): Notification
    {
        return new HealthCheckFailedNotification($this, channels: $this->channels);
    }
}
