<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route as RouteFacade;
use RoundlyConsulting\Alerts\Actions\BuildHealthReportAction;
use RoundlyConsulting\Alerts\Actions\RunHealthCheckAction;
use RoundlyConsulting\Alerts\Checks\ClosureCheck;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Exceptions\InvalidHealthCheck;
use RoundlyConsulting\Alerts\Facades\Health as HealthFacade;
use RoundlyConsulting\Alerts\Http\HealthController;
use RoundlyConsulting\Alerts\Status\HealthReport;
use RoundlyConsulting\Alerts\Support\PendingCheck;
use RoundlyConsulting\Alerts\Testing\HealthFake;

class Health
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
     * Register an inline closure-based check and return a builder for its options.
     */
    public function define(string $key, Closure $callback): PendingCheck
    {
        $pending = new PendingCheck($this, $key, $callback);

        $this->checks[$key] = new ClosureCheck($pending);

        return $pending;
    }

    /**
     * Replace a previously-defined check instance (used by the inline builder).
     */
    public function register(Check $check): self
    {
        $this->checks[$check->key()] = $check;

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

    /**
     * Run a check synchronously against a notifiable, applying alert/notify/recover
     * side effects, and return its result.
     */
    public function run(string|Check $check, Model $notifiable): CheckResult
    {
        $check = $this->resolveCheck($check);
        $this->register($check);

        $healthCheck = $this->resolveHealthCheckRow($check->key(), $notifiable);

        return app(RunHealthCheckAction::class)->execute($healthCheck);
    }

    /**
     * Normalise a class-string or instance into a Check, validating its type.
     */
    public function resolveCheck(string|Check $check): Check
    {
        if (is_string($check)) {
            $check = new $check;
        }

        if (! $check instanceof Check) {
            throw InvalidHealthCheck::doesntExtendBaseCheck($check);
        }

        return $check;
    }

    public function report(?Model $notifiable = null): HealthReport
    {
        return app(BuildHealthReportAction::class)->execute($notifiable);
    }

    public function status(?Model $notifiable = null): Status
    {
        return $this->report($notifiable)->overall();
    }

    /**
     * Opt-in JSON health endpoint. Call from the host app's routes file.
     */
    public function routes(?string $uri = null): Route
    {
        $uri ??= (string) config('alerts.route.uri', 'health');

        return RouteFacade::get($uri, HealthController::class)
            ->name((string) config('alerts.route.name', 'alerts.health'));
    }

    public function fake(): HealthFake
    {
        $fake = new HealthFake;

        app()->instance('health', $fake);
        app()->instance(self::class, $fake);

        HealthFacade::clearResolvedInstance('health');
        HealthFacade::swap($fake);

        return $fake;
    }

    private function resolveHealthCheckRow(string $key, Model $notifiable): HealthCheck
    {
        /** @var class-string<HealthCheck> $model */
        $model = config('alerts.health-check', HealthCheck::class);

        return $model::query()->firstOrCreate([
            'notifiable_type' => $notifiable->getMorphClass(),
            'notifiable_id' => $notifiable->getKey(),
            'health_check' => $key,
        ], [
            'frequency' => '* * * * *',
            'max_attempts' => 1,
            'decay_minutes' => 1,
            'meta' => [],
        ]);
    }
}
