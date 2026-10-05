<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Checks;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\CheckResult;
use RoundlyConsulting\Alerts\Notifications\HealthCheckFailedNotification;
use Throwable;

/**
 * Verifies a cache store accepts writes and returns the value it stored, under a key
 * unique to the run that it removes again.
 */
final class CacheCheck extends Check
{
    private ?string $store = null;

    public static function make(?string $store = null): self
    {
        $check = new self;
        $check->store = $store;

        return $check;
    }

    public function store(string $store): self
    {
        $this->store = $store;

        return $this;
    }

    public function check(): CheckResult
    {
        $store = $this->store ?? (string) config('cache.default');
        $sentinel = (string) Str::uuid();

        // A key of its own per run: two runs overlapping on one shared store (the check
        // run for several notifiables at the same tick) must never read each other's value.
        $key = 'alerts:cache-check:'.$sentinel;

        try {
            $cache = Cache::store($store);
            $cache->put($key, $sentinel, 10);
            $read = $cache->get($key);
            $cache->forget($key);
        } catch (Throwable $e) {
            return CheckResult::failed(
                (string) __('alerts::checks.cache_unreachable', ['store' => $store]),
                ['store' => $store, 'error' => $e->getMessage()],
            );
        }

        if ($read !== $sentinel) {
            return CheckResult::failed(
                (string) __('alerts::checks.cache_mismatch', ['store' => $store]),
                ['store' => $store],
            );
        }

        return CheckResult::ok(
            (string) __('alerts::checks.cache_ok', ['store' => $store]),
            ['store' => $store],
        );
    }

    public function notification(object $notifiable): Notification
    {
        return new HealthCheckFailedNotification($this, channels: $this->channels);
    }
}
