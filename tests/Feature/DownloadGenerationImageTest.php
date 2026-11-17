<?php

use App\Enums\GenerationStatus;
use App\Integrations\Pixelforge\PixelforgeClient;
use App\Jobs\DownloadGenerationImage;
use App\Models\Generation;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function succeededGeneration(): Generation
{
    return Generation::factory()->succeeded()->create(['provider_reference' => 'gen_1']);
}

function fakeImage(string $contentType = 'image/png', int $status = 200): void
{
    Http::fake([
        '*/generations/gen_1' => Http::response(['id' => 'gen_1', 'image_url' => 'https://cdn.example/signed.png']),
        'https://cdn.example/*' => Http::response('PNGBYTES', $status, ['Content-Type' => $contentType]),
    ]);
}

it('stores the image under user uuid, generation uuid and a uuid filename', function () {
    Storage::fake();
    fakeImage();
    $generation = succeededGeneration();

    (new DownloadGenerationImage($generation))->handle(app(PixelforgeClient::class));

    $generation->refresh();

    expect($generation->image_path)->toMatch("#^{$generation->user_id}/{$generation->id}/[0-9a-f-]{36}\\.png$#");
    Storage::assertExists($generation->image_path);
    expect(Storage::get($generation->image_path))->toBe('PNGBYTES');
});

it('does nothing when the image is already stored', function () {
    Storage::fake();
    fakeImage();
    $generation = succeededGeneration();
    $job = new DownloadGenerationImage($generation);

    $job->handle(app(PixelforgeClient::class));
    $path = $generation->refresh()->image_path;
    $job->handle(app(PixelforgeClient::class));

    expect($generation->refresh()->image_path)->toBe($path)
        ->and(Storage::allFiles())->toHaveCount(1);
});

it('does not download for a generation that has not succeeded', function () {
    Storage::fake();
    $generation = Generation::factory()->create(['status' => GenerationStatus::PROCESSING, 'provider_reference' => 'gen_1']);

    (new DownloadGenerationImage($generation))->handle(app(PixelforgeClient::class));

    Http::assertNothingSent();
    expect(Storage::allFiles())->toBeEmpty();
});

it('stores nothing and throws when the URL has expired or is not an image', function (string $contentType, int $status, string $exception) {
    Storage::fake();
    fakeImage($contentType, $status);
    $generation = succeededGeneration();

    expect(fn () => (new DownloadGenerationImage($generation))->handle(app(PixelforgeClient::class)))->toThrow($exception);

    expect($generation->refresh()->image_path)->toBeNull()
        ->and(Storage::allFiles())->toBeEmpty();
})->with([
    'expired url' => ['application/xml', 403, RequestException::class],
    'html instead of an image' => ['text/html', 200, RuntimeException::class],
]);
