<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Enums\Provider;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => Provider::PIXELFORGE,
            'status' => GenerationStatus::QUEUED,
            'prompt' => fake()->sentence(),
            'size' => ImageSize::SQUARE,
            'quality' => ImageQuality::MEDIUM,
            'reference_images' => [],
            'markup_basis_points' => 2500,
            'rates' => ['input_text' => 5000, 'input_image' => 10000, 'output' => 40000, 'reasoning' => 12500],
            'estimated_cost' => 50_000_000,
            'estimated_cost_max' => 100_000_000,
        ];
    }

    public function charged(int $chargedCost): static
    {
        return $this->succeeded()->state(fn () => ['charged_cost' => $chargedCost]);
    }

    public function withImage(): static
    {
        return $this->succeeded()->state(fn (array $attributes) => [
            'image_path' => fake()->uuid().'/'.fake()->uuid().'/'.fake()->uuid().'.png',
        ]);
    }

    public function failed(string $message = 'The model could not produce an image.'): static
    {
        return $this->state(fn () => [
            'status' => GenerationStatus::FAILED,
            'error_code' => 'generation_failed',
            'error_message' => $message,
            'completed_at' => now(),
        ]);
    }

    public function succeeded(): static
    {
        return $this->state(fn () => [
            'status' => GenerationStatus::SUCCEEDED,
            'completed_at' => now(),
        ]);
    }
}
