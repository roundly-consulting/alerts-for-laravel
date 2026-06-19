<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Alerts\Alert;

/**
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
            'notifiable_type' => 'tests',
            'notifiable_id' => $this->faker->randomNumber(),
            'health_check_id' => HealthCheckFactory::new(),
            'message' => $this->faker->sentence(),
            'meta' => [],
            'triggered_at' => now(),
            'recovered_at' => null,
        ];
    }

    public function recovered(): self
    {
        return $this->state(fn (): array => ['recovered_at' => now()]);
    }
}
