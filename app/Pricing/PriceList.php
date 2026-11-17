<?php

namespace App\Pricing;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;

/**
 * Everything needed to price a generation, as published by the provider.
 */
final readonly class PriceList
{
    /**
     * @param  array<string, array<string, int>>  $outputTokens  quality => size => tokens
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  quality => range
     */
    public function __construct(
        public Rates $rates,
        public int $charactersPerTextToken,
        public int $textVarianceBasisPoints,
        public int $tokensPerReferenceImage,
        public array $outputTokens,
        public array $reasoningTokens,
    ) {}

    public function outputTokensFor(ImageSize $size, ImageQuality $quality): int
    {
        return $this->outputTokens[$quality->value][$size->value];
    }

    /**
     * @return array{int, int} [min, max]
     */
    public function reasoningRangeFor(ImageQuality $quality): array
    {
        $range = $this->reasoningTokens[$quality->value];

        return [$range['min'], $range['max']];
    }
}
