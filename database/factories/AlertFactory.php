<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Support\HealthCheckModel;

/**
 * An alert as the pipeline opens one: on its health check's notifiable (the report and
 * the pipeline both match an alert by the check's notifiable), holding the check's open
 * slot until it is recovered. A check takes one open alert, so a second open one for the
 * same check is refused by the unique index, as it is in the pipeline.
 *
 * @extends Factory<Alert>
 */
final class AlertFactory extends Factory
{
    protected $model = Alert::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // First: the attributes below read the health check it resolves to.
            'health_check_id' => HealthCheckFactory::new(),
            'notifiable_type' => fn (array $attributes): ?string => $this->healthCheck($attributes)?->notifiable_type,
            'notifiable_id' => fn (array $attributes): mixed => $this->healthCheck($attributes)?->notifiable_id,
            'status' => Status::Failed,
            'message' => $this->faker->sentence(),
            'meta' => [],
            'triggered_at' => now(),
            'recovered_at' => null,
            'open_slot' => fn (array $attributes): ?int => $attributes['recovered_at'] === null ? Alert::OPEN_SLOT : null,
        ];
    }

    public function warning(): self
    {
        return $this->state(fn (): array => ['status' => Status::Warning]);
    }

    public function failed(): self
    {
        return $this->state(fn (): array => ['status' => Status::Failed]);
    }

    public function skipped(): self
    {
        return $this->state(fn (): array => ['status' => Status::Skipped]);
    }

    public function recovered(): self
    {
        return $this->state(fn (): array => ['recovered_at' => now(), 'open_slot' => null]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function healthCheck(array $attributes): ?HealthCheck
    {
        $id = $attributes['health_check_id'] ?? null;

        return $id === null ? null : HealthCheckModel::query()->withTrashed()->whereKey($id)->first();
    }
}
