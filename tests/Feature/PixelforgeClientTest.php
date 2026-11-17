<?php

use App\Integrations\Pixelforge\PixelforgeClient;
use App\Pricing\PriceList;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function pricingFixture(): array
{
    return json_decode(file_get_contents(__DIR__.'/../Fixtures/pixelforge-pricing.json'), true);
}

it('serves the last known price list and logs when the pricing endpoint fails', function () {
    Cache::forever('pixelforge.pricing.stale', pricingFixture());
    Http::fake(['*/pricing' => Http::response('boom', 500)]);
    Log::shouldReceive('warning')->once();

    expect(app(PixelforgeClient::class)->pricing())->toBeInstanceOf(PriceList::class);
});

it('throws when pricing has never been fetched and the endpoint is unusable', function (Closure $response, string $exception) {
    Http::fake(['*/pricing' => $response]);

    expect(fn () => app(PixelforgeClient::class)->pricing())->toThrow($exception);
})->with([
    'server error' => [fn () => Http::response('boom', 500), RequestException::class],
    'invalid body' => [fn () => Http::response('not json', 200), UnexpectedValueException::class],
]);
