<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\Alerts\Enums\Status;

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
            'status' => Status::Failed,
            'message' => $this->faker->sentence(),
            'meta' => [],
            'triggered_at' => now(),
            'recovered_at' => null,
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
        return $this->state(fn (): array => ['recovered_at' => now()]);
    }
}
