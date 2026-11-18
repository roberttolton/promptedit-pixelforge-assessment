<?php

namespace App\Integrations\Pixelforge;

use App\Models\Generation;
use App\Pricing\Money;
use App\Pricing\PriceList;
use App\Pricing\Pricing;
use App\Pricing\Rates;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use UnexpectedValueException;

class PixelforgeClient
{
    private const string PRICING_CACHE_KEY = 'pixelforge.pricing';

    private const string PRICING_STALE_CACHE_KEY = 'pixelforge.pricing.stale';

    private const int PRICING_CACHE_TTL_SECONDS = 3600;

    /**
     * Cached for an hour; if the endpoint is down, the last copy ever fetched is served.
     */
    public function pricing(): PriceList
    {
        return self::priceListFrom(Cache::get(self::PRICING_CACHE_KEY) ?? $this->fetchPricing());
    }

    /**
     * Use the Generation's own UUID as the Idempotency-Key, so retrying after a
     * timeout can never create (or bill) a second generation. The UUID is also sent in
     * metadata so a webhook can find the Generation row before the Pixelforge ID from
     * the POST response has been stored, helping to recover from the race-condition of a webhook arriving first (or even if the POST data is unreadable).
     *
     * @return array<string, mixed>
     *
     * @throws PixelforgeException
     * @throws ConnectionException
     */
    public function createGeneration(Generation $generation): array
    {
        $response = $this->http()
            ->withHeader('Idempotency-Key', $generation->id)
            ->post('generations', array_filter([
                'prompt' => $generation->prompt,
                'size' => $generation->size->value,
                'quality' => $generation->quality->value,
                'reference_images' => $generation->reference_images,
                'metadata' => ['generation_id' => $generation->id],
            ]));

        if ($response->failed()) {
            throw PixelforgeException::fromResponse($response);
        }

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PixelforgeException
     * @throws ConnectionException
     */
    public function getGeneration(string $reference): array
    {
        $response = $this->http()->get("generations/{$reference}");

        if ($response->failed()) {
            throw PixelforgeException::fromResponse($response);
        }

        return $response->json();
    }

    /**
     * What Pixelforge billed this API key per UTC completion day, as nanodollars.
     * Days without usage are included with 0.
     *
     * @return array<string, int> Keyed by YYYY-MM-DD.
     *
     * @throws PixelforgeException
     * @throws ConnectionException
     */
    public function usageByDay(string $startDate, string $endDate): array
    {
        $response = $this->http()->get('usage', ['start_date' => $startDate, 'end_date' => $endDate]);

        if ($response->failed()) {
            throw PixelforgeException::fromResponse($response);
        }

        $days = [];

        foreach ($response->json('data') ?? throw new UnexpectedValueException('Pixelforge usage response has no data.') as $day) {
            $days[$day['date']] = Money::parseUsd($day['cost_usd']);
        }

        return $days;
    }

    /**
     * @param  array<string, string>  $rates  USD per 1M tokens, as in GET /v1/pricing and on every generation.
     */
    public static function ratesFrom(array $rates): Rates
    {
        return new Rates(
            inputText: Rates::nanoPerToken($rates['input_text_tokens']),
            inputImage: Rates::nanoPerToken($rates['input_image_tokens']),
            output: Rates::nanoPerToken($rates['output_tokens']),
            reasoning: Rates::nanoPerToken($rates['reasoning_tokens']),
        );
    }

    /**
     * @param  array<string, mixed>  $json  The GET /v1/pricing response.
     */
    public static function priceListFrom(array $json): PriceList
    {
        $tokens = $json['tokens'];

        return new PriceList(
            rates: self::ratesFrom($json['rates']),
            charactersPerTextToken: $tokens['characters_per_text_token'],
            textVarianceBasisPoints: (int) round($tokens['text_token_variance'] * Pricing::BASIS_POINTS_DENOMINATOR),
            tokensPerReferenceImage: $tokens['per_reference_image'],
            outputTokens: $tokens['output'],
            reasoningTokens: $tokens['reasoning'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchPricing(): array
    {
        try {

            $data = $this->http()->get('pricing')->throw()->json();

            if (! is_array($data)) {
                throw new UnexpectedValueException('Pixelforge pricing response was not valid JSON.');
            }

        } catch (ConnectionException|RequestException|UnexpectedValueException $e) {

            $stale = Cache::get(self::PRICING_STALE_CACHE_KEY) ?? throw $e;

            Log::warning('Pixelforge pricing fetch failed; serving the last known price list.', ['error' => $e->getMessage()]);

            return $stale;

        }

        Cache::put(self::PRICING_CACHE_KEY, $data, self::PRICING_CACHE_TTL_SECONDS);
        Cache::forever(self::PRICING_STALE_CACHE_KEY, $data);

        return $data;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(config('services.pixelforge.base_url'))
            ->withToken((string) config('services.pixelforge.api_key'))
            ->acceptJson()
            ->timeout(15);
    }
}
