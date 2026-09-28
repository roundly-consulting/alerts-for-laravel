<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Support;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\CheckResult;
use RoundlyConsulting\Alerts\DataTransferObjects\ScheduleHealthCheckData;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\HealthManager;
use RoundlyConsulting\Alerts\Status\HealthReport;

/**
 * `Health::for($notifiable)` — every health operation scoped to one notifiable.
 * Each method goes through the manager, so host overrides and `Health::fake()` see it.
 */
final readonly class NotifiableHealth
{
    /**
     * @internal build it with `Health::for($notifiable)`
     */
    public function __construct(
        private HealthManager $health,
        private Model $notifiable,
    ) {}

    /**
     * Run a check now against this notifiable, applying alert/notify/recover side
     * effects. Pass a Check (class-string or instance) to run it ad hoc, or one of this
     * notifiable's scheduled rows to run that schedule; another notifiable's row is
     * refused with InvalidHealthCheck.
     */
    public function run(string|Check|HealthCheck $check): CheckResult
    {
        return $this->health->runFor($this->notifiable, $check);
    }

    /**
     * @param  list<string>|null  $tags
     */
    public function report(?array $tags = null): HealthReport
    {
        return $this->health->reportFor($this->notifiable, $tags);
    }

    /**
     * @param  list<string>|null  $tags
     */
    public function status(?array $tags = null): Status
    {
        return $this->report($tags)->overall();
    }

    /**
     * Start a fluent schedule for a Check class-string or registered key; `save()` persists it.
     */
    public function monitor(string $check): PendingScheduledCheck
    {
        return new PendingScheduledCheck($this->health, $this->notifiable, $check);
    }

    /**
     * Schedule a check from a DTO.
     */
    public function schedule(ScheduleHealthCheckData $data): HealthCheck
    {
        return $this->health->scheduleFor($this->notifiable, $data);
    }

    /**
     * Stop monitoring: soft-delete this notifiable's schedules for a check (class-string,
     * key or instance), or one given row. A row scheduled against another notifiable is
     * refused with InvalidHealthCheck.
     *
     * @return int how many schedules were removed
     */
    public function unmonitor(string|Check|HealthCheck $check): int
    {
        return $this->health->unscheduleFor($this->notifiable, $check);
    }

    /**
     * The checks scheduled against this notifiable, oldest first.
     *
     * @return Collection<int, HealthCheck>
     */
    public function monitors(): Collection
    {
        return $this->health->scheduledFor($this->notifiable);
    }
}
