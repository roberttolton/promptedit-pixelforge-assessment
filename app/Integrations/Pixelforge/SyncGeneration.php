<?php

namespace App\Integrations\Pixelforge;

use App\Enums\GenerationStatus;
use App\Enums\GenerationSyncResult;
use App\Enums\Provider;
use App\Jobs\DownloadGenerationImage;
use App\Models\Generation;
use App\Pricing\Pricing;
use App\Pricing\Rates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

/**
 * Applies a Pixelforge generation object (from a webhook or from polling) to the stored Generation row.
 *
 * The row is locked and the status machine decides whether the move is OK, so the
 * charge is written exactly once, whatever order or how many times the updates might arrive.
 */
class SyncGeneration
{
    public function __construct(
        private readonly Pricing $pricing,
        private readonly PixelforgeClient $client,
    ) {}

    /**
     * @param  array<string, mixed>  $object  The generation object.
     */
    public function __invoke(array $object): GenerationSyncResult
    {
        $succeeded = null;

        $result = DB::transaction(function () use ($object, &$succeeded): GenerationSyncResult {

            $generation = $this->findLocked($object);

            if ($generation === null) {
                return GenerationSyncResult::UNKNOWN_GENERATION;
            }

            $generation->provider_reference ??= $object['id'];

            $target = GenerationStatus::tryFrom((string) ($object['status'] ?? ''));

            if ($target === null || ! $generation->status->canTransitionTo($target)) {
                $generation->save();

                return GenerationSyncResult::STALE;
            }

            match ($target) {
                GenerationStatus::SUCCEEDED => $this->applySuccess($succeeded = $generation, $object),
                GenerationStatus::FAILED => $this->applyFailure($generation, $object),
                default => null,
            };

            $generation->status = $target;
            $generation->save();

            return GenerationSyncResult::APPLIED;

        });

        if ($succeeded !== null) {
            DownloadGenerationImage::dispatch($succeeded);
            $this->warnOnPriceListDrift($succeeded);
        }

        return $result;
    }

    /**
     * Matches on the Pixelforge ID, or on the metadata UUID sent, so an event that
     * arrives before the ID was stored (or after a lost response) still finds its Generation row.
     *
     * @param  array<string, mixed>  $object  The generation object.
     */
    private function findLocked(array $object): ?Generation
    {
        $generationId = $object['metadata']['generation_id'] ?? null;

        return Generation::query()
            ->where('provider', Provider::PIXELFORGE)
            ->where(function ($query) use ($object, $generationId): void {
                $query->where('provider_reference', $object['id']);

                if (is_string($generationId) && Str::isUuid($generationId)) {
                    $query->orWhere('id', $generationId);
                }
            })
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function applySuccess(Generation $generation, array $object): void
    {
        // Never charge on a succeeded event that cannot be priced; throwing makes Pixelforge retry the webhook.
        $usage = $object['usage'] ?? throw new UnexpectedValueException('Succeeded generation has no usage.');

        // The rates Pixelforge actually billed with are authoritative, and are what /v1/usage
        // reports. The snapshot stored when the Generation was created is only the fallback.
        $rates = isset($object['rates']) ? PixelforgeClient::ratesFrom($object['rates']) : Rates::fromArray($generation->rates);

        $providerCost = $this->pricing->providerCost(
            $usage['input_text_tokens'],
            $usage['input_image_tokens'],
            $usage['output_tokens'],
            $usage['reasoning_tokens'],
            $rates,
        );

        $generation->fill([
            'rates' => $rates->toArray(),
            'input_text_tokens' => $usage['input_text_tokens'],
            'input_image_tokens' => $usage['input_image_tokens'],
            'output_tokens' => $usage['output_tokens'],
            'reasoning_tokens' => $usage['reasoning_tokens'],
            'provider_cost' => $providerCost,
            'charged_cost' => $this->pricing->applyMarkup($providerCost, $generation->markup_basis_points),
            'completed_at' => Carbon::parse($object['completed_at'] ?? now()),
        ]);
    }

    /**
     * Failed generations cost nothing, so no cost columns are written.
     *
     * @param  array<string, mixed>  $object
     */
    private function applyFailure(Generation $generation, array $object): void
    {
        $generation->fill([
            'error_code' => $object['error']['code'] ?? null,
            'error_message' => $object['error']['message'] ?? null,
            'completed_at' => Carbon::parse($object['completed_at'] ?? now()),
        ]);
    }

    /**
     * Best effort and outside the transaction: a pricing outage must never block a charge.
     */
    private function warnOnPriceListDrift(Generation $generation): void
    {
        try {

            $drift = $this->pricing->findDrift(
                $generation->prompt,
                $generation->size,
                $generation->quality,
                count($generation->reference_images),
                [
                    'input_text_tokens' => $generation->input_text_tokens,
                    'input_image_tokens' => $generation->input_image_tokens,
                    'output_tokens' => $generation->output_tokens,
                    'reasoning_tokens' => $generation->reasoning_tokens,
                ],
                $this->client->pricing(),
            );

        } catch (Throwable) {
            return;
        }

        if ($drift !== []) {

            Log::warning('Pixelforge usage differs from the published price list; estimates may be stale.', [
                'generation_id' => $generation->id,
                'drift' => $drift,
            ]);

        }
    }
}
