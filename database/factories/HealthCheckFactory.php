<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Alerts\HealthCheck;

/**
 * @extends Factory<HealthCheck>
 */
final class HealthCheckFactory extends Factory
{
    protected $model = HealthCheck::class;

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
