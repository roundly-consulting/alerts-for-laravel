<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route as RouteFacade;
use RoundlyConsulting\Alerts\Actions\BuildHealthReportAction;
use RoundlyConsulting\Alerts\Actions\MuteAlertsAction;
use RoundlyConsulting\Alerts\Actions\PruneHealthCheckRunsAction;
use RoundlyConsulting\Alerts\Actions\RunDueHealthChecksAction;
use RoundlyConsulting\Alerts\Actions\RunHealthCheckNowAction;
use RoundlyConsulting\Alerts\Actions\ScheduleHealthCheckAction;
use RoundlyConsulting\Alerts\Actions\UnmuteAlertsAction;
use RoundlyConsulting\Alerts\Actions\UnscheduleHealthCheckAction;
use RoundlyConsulting\Alerts\Checks\ClosureCheck;
use RoundlyConsulting\Alerts\DataTransferObjects\ScheduleHealthCheckData;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Exceptions\InvalidHealthCheck;
use RoundlyConsulting\Alerts\Http\HealthController;
use RoundlyConsulting\Alerts\Status\HealthReport;
use RoundlyConsulting\Alerts\Support\AlertSilenceModel;
use RoundlyConsulting\Alerts\Support\HealthCheckModel;
use RoundlyConsulting\Alerts\Support\NotifiableHealth;
use RoundlyConsulting\Alerts\Support\PendingCheck;
use RoundlyConsulting\Alerts\Support\Silences;

/**
 * The root of the `Health` facade: the check registry, the model-scoped handle
 * (`for()`), the silences sub-accessor and the scheduler/maintenance verbs.
 *
 * Deliberately not `final`: `Testing\HealthFake` extends it, so a constructor-injected
 * manager keeps working under `Health::fake()`.
 */
class HealthManager
{
    /**
     * @var array<string, Check>
     */
    protected array $checks = [];

    public function __construct(
        protected readonly Container $container,
    ) {}

    /**
     * @param  array<int, string|object>  $checks
     */
    public function checks(array $checks): static
    {
        foreach ($checks as $check) {
            $this->check($check);
        }

        return $this;
    }

    public function check(string|object $healthCheck): static
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
     * Everything scoped to one notifiable: run a check now, its report and status,
     * and the checks scheduled against it.
     */
    public function for(Model $notifiable): NotifiableHealth
    {
        return new NotifiableHealth($this, $notifiable);
    }

    /**
     * The current report across every scheduled check, optionally narrowed by tags.
     *
     * @param  list<string>|null  $tags
     */
    public function report(?array $tags = null): HealthReport
    {
        return $this->reportFor(null, $tags);
    }

    /**
     * @param  list<string>|null  $tags
     */
    public function status(?array $tags = null): Status
    {
        return $this->report($tags)->overall();
    }

    /**
     * Maintenance windows: mute, unmute and inspect alert silences.
     */
    public function silences(): Silences
    {
        return new Silences($this);
    }

    /**
     * Queue a run for every scheduled check whose cron is due now.
     *
     * @return int how many runs were queued
     */
    public function runDue(): int
    {
        return $this->container->make(RunDueHealthChecksAction::class)->execute();
    }

    /**
     * Delete run history older than `$days` (default: `alerts.history.retention_days`).
     *
     * @return int how many runs were deleted
     */
    public function prune(?int $days = null): int
    {
        return $this->container->make(PruneHealthCheckRunsAction::class)->execute($days);
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

    /**
     * Replace a registered check instance.
     *
     * @internal used by the run path to keep the registry in step with the instance it runs
     */
    public function register(Check $check): static
    {
        $this->checks[$check->key()] = $check;

        return $this;
    }

    /**
     * Normalise a class-string or instance into a Check, validating its type.
     *
     * @internal
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

    /*
     * The operations behind for() and silences(). They are public only so the handle
     * and the sub-accessor can reach them, and @internal so the facade never documents
     * them. They are also the ONE place HealthFake overrides: every call — through the
     * facade, an injected manager or the UsesHealthChecks trait — lands here.
     */

    /**
     * @internal the body of `for($notifiable)->run()`
     */
    public function runFor(Model $notifiable, string|Check|HealthCheck $check): CheckResult
    {
        if (! $check instanceof HealthCheck) {
            $check = $this->resolveCheck($check);
            $this->register($check);
        }

        return $this->container->make(RunHealthCheckNowAction::class)->execute($notifiable, $check);
    }

    /**
     * @internal the body of `report()` and `for($notifiable)->report()`
     *
     * @param  list<string>|null  $tags
     */
    public function reportFor(?Model $notifiable, ?array $tags = null): HealthReport
    {
        return $this->container->make(BuildHealthReportAction::class)->execute($notifiable, $tags);
    }

    /**
     * @internal the body of `for($notifiable)->schedule()` and `->monitor()->save()`
     */
    public function scheduleFor(Model $notifiable, ScheduleHealthCheckData $data): HealthCheck
    {
        return $this->container->make(ScheduleHealthCheckAction::class)->execute($notifiable, $data);
    }

    /**
     * @internal the body of `for($notifiable)->unmonitor()`
     */
    public function unscheduleFor(Model $notifiable, string|Check|HealthCheck $check): int
    {
        return $this->container->make(UnscheduleHealthCheckAction::class)->execute($notifiable, $check);
    }

    /**
     * @internal the body of `for($notifiable)->monitors()`
     *
     * @return EloquentCollection<int, HealthCheck>
     */
    public function scheduledFor(Model $notifiable): EloquentCollection
    {
        return HealthCheckModel::query()
            ->where('notifiable_type', $notifiable->getMorphClass())
            ->where('notifiable_id', $notifiable->getKey())
            ->whereNotNull('frequency')
            ->oldest('id')
            ->get();
    }

    /**
     * @internal the body of `silences()->mute()`
     */
    public function muteFor(
        string $key,
        ?CarbonInterface $until = null,
        ?Model $notifiable = null,
        ?string $reason = null,
    ): AlertSilence {
        return $this->container->make(MuteAlertsAction::class)->execute($key, $until, $notifiable, $reason);
    }

    /**
     * @internal the body of `silences()->unmute()`
     */
    public function unmuteFor(string $key, ?Model $notifiable = null): int
    {
        return $this->container->make(UnmuteAlertsAction::class)->execute($key, $notifiable);
    }

    /**
     * @internal the body of `silences()->isMuted()`
     */
    public function mutedFor(string $key, ?Model $notifiable = null): bool
    {
        if (config('alerts.silence', true) !== true) {
            return false;
        }

        return AlertSilenceModel::query()
            ->matching([$key], $notifiable)
            ->active(now())
            ->exists();
    }

    /**
     * @internal the body of `silences()->active()`
     *
     * @return EloquentCollection<int, AlertSilence>
     */
    public function silencesFor(?Model $notifiable = null): EloquentCollection
    {
        $query = AlertSilenceModel::query()->active(now());

        if ($notifiable !== null) {
            $query->where(function (Builder $q) use ($notifiable): void {
                $q->whereNull('notifiable_id')
                    ->orWhere(function (Builder $scoped) use ($notifiable): void {
                        $scoped->where('notifiable_type', $notifiable->getMorphClass())
                            ->where('notifiable_id', $notifiable->getKey());
                    });
            });
        }

        return $query->oldest('id')->get();
    }
}
