<?php

namespace Database\Factories;

use App\Enums\Provider;
use App\Enums\WebhookOutcome;
use App\Integrations\Pixelforge\WebhookEventType;
use App\Models\Webhook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Webhook>
 */
class WebhookFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => Provider::PIXELFORGE,
            'external_id' => 'evt_'.fake()->unique()->bothify('????????'),
            'type' => WebhookEventType::GENERATION_PROCESSING->value,
            'payload' => [],
            'outcome' => WebhookOutcome::RECEIVED,
        ];
    }
}
