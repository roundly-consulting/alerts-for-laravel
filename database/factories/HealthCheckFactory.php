<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Support\HealthCheckModel;

/**
 * @extends Factory<HealthCheck>
 */
final class HealthCheckFactory extends Factory
{
    protected $model = HealthCheck::class;

    /**
     * Build the configured model, not the packaged one: a host that swaps
     * `alerts.health-check` gets its own class, with its casts,
     * events and observers.
     *
     * @return class-string<HealthCheck>
     */
    public function modelName(): string
    {
        return HealthCheckModel::class();
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notifiable_type' => 'tests',
            'notifiable_id' => $this->faker->randomNumber(),
            'health_check' => $this->faker->slug(),
            'frequency' => '* * * * *',
            'max_attempts' => 1,
            'decay_minutes' => 1,
            'meta' => [],
        ];
    }
}
