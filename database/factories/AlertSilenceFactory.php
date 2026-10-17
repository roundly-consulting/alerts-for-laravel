<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Alerts\AlertSilence;
use RoundlyConsulting\Alerts\Support\AlertSilenceModel;

/**
 * @extends Factory<AlertSilence>
 */
final class AlertSilenceFactory extends Factory
{
    protected $model = AlertSilence::class;

    /**
     * Build the configured model, not the packaged one: a host that swaps
     * `alerts.silence-model` gets its own class, with its casts,
     * events and observers.
     *
     * @return class-string<AlertSilence>
     */
    public function modelName(): string
    {
        return AlertSilenceModel::class();
    }

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
