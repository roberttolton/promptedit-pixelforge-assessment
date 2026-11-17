<?php

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Enums\Provider;
use App\Integrations\Pixelforge\PixelforgeClient;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pricing\Pricing;

class CreateGeneration
{
    public function __construct(
        private readonly PixelforgeClient $client,
        private readonly Pricing $pricing,
    ) {}

    /**
     * What the user will be charged (provider cost plus markup), in nanodollars.
     *
     * @param  list<string>  $referenceImages
     * @return array{min: int, expected: int, max: int}
     */
    public function estimate(string $prompt, ImageSize $size, ImageQuality $quality, array $referenceImages): array
    {
        $provider = $this->pricing->estimateProviderCost($prompt, $size, $quality, count($referenceImages), $this->client->pricing());
        $markup = $this->markupBasisPoints();

        return array_map(fn (int $cost): int => $this->pricing->applyMarkup($cost, $markup), $provider);
    }

    /**
     * Save the row first (status PENDING), then submit in the background, so a webhook
     * or a retry can never find nothing to attach to.
     *
     * @param  list<string>  $referenceImages
     */
    public function __invoke(User $user, string $prompt, ImageSize $size, ImageQuality $quality, array $referenceImages = []): Generation
    {
        $estimate = $this->estimate($prompt, $size, $quality, $referenceImages);

        $generation = Generation::create([
            'user_id' => $user->id,
            'provider' => Provider::PIXELFORGE,
            'status' => GenerationStatus::PENDING,
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $quality,
            'reference_images' => $referenceImages,
            'markup_basis_points' => $this->markupBasisPoints(),
            'rates' => $this->client->pricing()->rates->toArray(),
            'estimated_cost' => $estimate['expected'],
            'estimated_cost_max' => $estimate['max'],
        ]);

        SubmitGeneration::dispatch($generation)->afterCommit();

        return $generation;
    }

    private function markupBasisPoints(): int
    {
        return config('services.pixelforge.markup_basis_points');
    }
}
