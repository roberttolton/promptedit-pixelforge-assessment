<?php

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Integrations\Pixelforge\PixelforgeClient;
use App\Pricing\Pricing;
use App\Pricing\Rates;

beforeEach(function () {
    $this->pricing = new Pricing;
    $this->priceList = PixelforgeClient::priceListFrom(
        json_decode(file_get_contents(__DIR__.'/../Fixtures/pixelforge-pricing.json'), true),
    );
});

it('converts dollars per million tokens to exact nanodollars per token', function () {
    expect(Rates::nanoPerToken('5.00'))->toBe(5000)
        ->and(Rates::nanoPerToken('12.50'))->toBe(12500);
});

it('rejects a rate that is not a number', function () {
    expect(fn () => Rates::nanoPerToken('free'))->toThrow(InvalidArgumentException::class);
});

it('round-trips rates through the array stored on a generation', function () {
    expect(Rates::fromArray($this->priceList->rates->toArray()))->toEqual($this->priceList->rates);
});

it('maps the published price list without floats', function () {
    expect($this->priceList->rates->toArray())->toBe(['input_text' => 5000, 'input_image' => 10000, 'output' => 40000, 'reasoning' => 12500])
        ->and($this->priceList->textVarianceBasisPoints)->toBe(1500)
        ->and($this->priceList->outputTokensFor(ImageSize::LANDSCAPE, ImageQuality::HIGH))->toBe(6450);
});

it('estimates text tokens as ceil(chars / 4) counting unicode characters', function () {
    expect($this->pricing->estimateTextTokens(str_repeat('a', 400), $this->priceList))->toBe(100)
        ->and($this->pricing->estimateTextTokens('abcde', $this->priceList))->toBe(2)
        ->and($this->pricing->estimateTextTokens(str_repeat('é', 8), $this->priceList))->toBe(2);
});

it('matches the documented worst case maximum of $0.373875', function () {
    $estimate = $this->pricing->estimateProviderCost(
        str_repeat('a', 400), ImageSize::LANDSCAPE, ImageQuality::HIGH, 2, $this->priceList,
    );

    expect($estimate['max'])->toBe(373_875_000)
        ->and($estimate['min'])->toBeLessThan($estimate['expected'])
        ->and($estimate['expected'])->toBeLessThan($estimate['max']);
});

it('computes the exact provider cost from usage', function () {
    // 100 text, 1530 image, 6450 output, 4000 reasoning
    expect($this->pricing->providerCost(100, 1530, 6450, 4000, $this->priceList->rates))
        ->toBe(500_000 + 15_300_000 + 258_000_000 + 50_000_000);
});

it('applies the markup in basis points and rounds up', function () {
    expect($this->pricing->applyMarkup(1_000_000_000, 2500))->toBe(1_250_000_000)
        ->and($this->pricing->applyMarkup(3, 2500))->toBe(4) // 3.75 -> 4, never undercharge
        ->and($this->pricing->applyMarkup(0, 2500))->toBe(0);
});

it('reports no drift when usage matches the price list, and flags each difference otherwise', function () {
    $usage = ['input_text_tokens' => 10, 'input_image_tokens' => 0, 'output_tokens' => 280, 'reasoning_tokens' => 271];
    $drift = fn (array $overrides) => $this->pricing->findDrift('[succeed] A small lighthouse at dawn', ImageSize::SQUARE, ImageQuality::LOW, 0, $overrides + $usage, $this->priceList);

    expect($drift([]))->toBe([])
        ->and($drift(['output_tokens' => 300]))->toHaveCount(1)
        ->and($drift(['reasoning_tokens' => 501]))->toHaveCount(1)
        ->and($drift(['input_image_tokens' => 765]))->toHaveCount(1)
        ->and($drift(['input_text_tokens' => 50]))->toHaveCount(1);
});
