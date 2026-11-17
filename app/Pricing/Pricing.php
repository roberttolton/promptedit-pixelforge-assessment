<?php

namespace App\Pricing;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;

/**
 * All money is integer nanodollars (1 USD = 1_000_000_000), no floats.
 */
final class Pricing
{
    public const int BASIS_POINTS_DENOMINATOR = 10_000;

    public function estimateTextTokens(string $prompt, PriceList $priceList): int
    {
        $perToken = $priceList->charactersPerTextToken;

        return intdiv(mb_strlen($prompt) + $perToken - 1, $perToken);
    }

    /**
     * The estimate plus or minus the published variance, rounded outwards.
     *
     * @return array{int, int} [min, max]
     */
    public function textTokenBounds(string $prompt, PriceList $priceList): array
    {
        $estimate = $this->estimateTextTokens($prompt, $priceList);
        $variance = $priceList->textVarianceBasisPoints;

        return [
            intdiv($estimate * (self::BASIS_POINTS_DENOMINATOR - $variance), self::BASIS_POINTS_DENOMINATOR),
            intdiv($estimate * (self::BASIS_POINTS_DENOMINATOR + $variance) + self::BASIS_POINTS_DENOMINATOR - 1, self::BASIS_POINTS_DENOMINATOR),
        ];
    }

    /**
     * Provider cost range before markup. The text count has a published variance and
     * reasoning is random, so a [min, expected, max] range is returned rather than one wrong number.
     *
     * @return array{min: int, expected: int, max: int}
     */
    public function estimateProviderCost(string $prompt, ImageSize $size, ImageQuality $quality, int $referenceImageCount, PriceList $priceList): array
    {
        $text = $this->estimateTextTokens($prompt, $priceList);
        [$textMin, $textMax] = $this->textTokenBounds($prompt, $priceList);
        [$reasoningMin, $reasoningMax] = $priceList->reasoningRangeFor($quality);

        $cost = fn (int $textTokens, int $reasoningTokens): int => $this->providerCost(
            $textTokens,
            $referenceImageCount * $priceList->tokensPerReferenceImage,
            $priceList->outputTokensFor($size, $quality),
            $reasoningTokens,
            $priceList->rates,
        );

        return [
            'min' => $cost($textMin, $reasoningMin),
            'expected' => $cost($text, intdiv($reasoningMin + $reasoningMax, 2)),
            'max' => $cost($textMax, $reasoningMax),
        ];
    }

    public function providerCost(int $textTokens, int $imageTokens, int $outputTokens, int $reasoningTokens, Rates $rates): int
    {
        return $textTokens * $rates->inputText
            + $imageTokens * $rates->inputImage
            + $outputTokens * $rates->output
            + $reasoningTokens * $rates->reasoning;
    }

    /**
     * Adds the markup, rounding UP to the next nanodollar so the user is never undercharged.
     */
    public function applyMarkup(int $providerCost, int $markupBasisPoints): int
    {
        return $providerCost + intdiv($providerCost * $markupBasisPoints + self::BASIS_POINTS_DENOMINATOR - 1, self::BASIS_POINTS_DENOMINATOR);
    }

    /**
     * Compares what a finished generation actually used with what the published price
     * list says it should have used. Any entry means the cost estimates may be out of date.
     *
     * @param  array{input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int}  $usage
     * @return list<string>
     */
    public function findDrift(string $prompt, ImageSize $size, ImageQuality $quality, int $referenceImageCount, array $usage, PriceList $priceList): array
    {
        $drift = [];

        $expectedOutput = $priceList->outputTokensFor($size, $quality);

        if ($usage['output_tokens'] !== $expectedOutput) {
            $drift[] = "output tokens {$usage['output_tokens']}, price list says {$expectedOutput}";
        }

        $expectedImages = $referenceImageCount * $priceList->tokensPerReferenceImage;

        if ($usage['input_image_tokens'] !== $expectedImages) {
            $drift[] = "input image tokens {$usage['input_image_tokens']}, price list says {$expectedImages}";
        }

        [$textMin, $textMax] = $this->textTokenBounds($prompt, $priceList);

        if ($usage['input_text_tokens'] < $textMin || $usage['input_text_tokens'] > $textMax) {
            $drift[] = "input text tokens {$usage['input_text_tokens']}, expected {$textMin}-{$textMax}";
        }

        [$reasoningMin, $reasoningMax] = $priceList->reasoningRangeFor($quality);

        if ($usage['reasoning_tokens'] < $reasoningMin || $usage['reasoning_tokens'] > $reasoningMax) {
            $drift[] = "reasoning tokens {$usage['reasoning_tokens']}, expected {$reasoningMin}-{$reasoningMax}";
        }

        return $drift;
    }
}
