<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\HealthCheckRun;
use RoundlyConsulting\Alerts\Support\HealthCheckRunModel;

/**
 * @extends Factory<HealthCheckRun>
 */
final class HealthCheckRunFactory extends Factory
{
    protected $model = HealthCheckRun::class;

    /**
     * Build the configured model, not the packaged one: a host that swaps
     * `alerts.history.model` gets its own class, with its casts,
     * events and observers.
     *
     * @return class-string<HealthCheckRun>
     */
    public function modelName(): string
    {
        return HealthCheckRunModel::class();
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'health_check_id' => HealthCheck::factory(),
            'status' => Status::Ok,
            'duration_ms' => $this->faker->numberBetween(1, 500),
            'message' => null,
            'meta' => [],
            'ran_at' => now(),
        ];
    }
}
