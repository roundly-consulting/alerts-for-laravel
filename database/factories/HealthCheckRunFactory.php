<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\HealthCheckRun;

/**
 * @extends Factory<HealthCheckRun>
 */
final class HealthCheckRunFactory extends Factory
{
    protected $model = HealthCheckRun::class;

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
