<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Integrations\Pixelforge\PixelforgeClient;
use App\Integrations\Pixelforge\PixelforgeException;
use App\Models\Generation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Pixelforge image URLs expire after 24 hours, so a copy is kept at
 * {user uuid}/{generation uuid}/{uuid}.png on the default (private) disk.
 *
 * Failing to download never affects the charge.
 */
class DownloadGenerationImage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 600;

    public function __construct(public Generation $generation) {}

    public function handle(PixelforgeClient $client): void
    {
        $generation = $this->generation->refresh();

        if ($generation->image_path !== null || $generation->status !== GenerationStatus::SUCCEEDED) {
            return;
        }

        try {

            $imageUrl = $client->getGeneration($generation->provider_reference)['image_url'] ?? null;

        } catch (PixelforgeException $e) {

            if ($e->isRetryable()) {
                $this->release($e->retryAfter ?? 5);

                return;
            }

            throw $e;
        }

        if ($imageUrl === null) {
            throw new RuntimeException('Pixelforge returned no image_url for a succeeded generation.');
        }

        $response = Http::timeout(30)->get($imageUrl)->throw();

        if (! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
            throw new RuntimeException('Pixelforge image URL did not return an image.');
        }

        $path = "{$generation->user_id}/{$generation->id}/".Str::uuid().'.png';

        Storage::put($path, $response->body(), 'private');

        $saved = Generation::whereKey($generation->id)->whereNull('image_path')
            ->update([
                'image_path' => $path,
            ]);

        if ($saved === 0) {
            Storage::delete($path);
        }
    }

    public function uniqueId(): string
    {
        return $this->generation->getKey();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 30, 120, 600];
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('Could not store the generation image; the history page will show it as unavailable.', [
            'generation_id' => $this->generation->getKey(),
            'error' => $exception->getMessage(),
        ]);
    }
}
