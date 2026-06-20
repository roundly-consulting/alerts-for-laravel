<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Alerts\AlertSilence;

/**
 * @extends Factory<AlertSilence>
 */
final class AlertSilenceFactory extends Factory
{
    protected $model = AlertSilence::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => $this->faker->slug(),
            'notifiable_type' => null,
            'notifiable_id' => null,
            'reason' => null,
            'starts_at' => null,
            'ends_at' => null,
        ];
    }
}
