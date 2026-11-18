<?php

namespace App\Http\Resources;

use App\Enums\ImageAvailability;
use App\Models\Generation;
use App\Pricing\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Generation $resource
 */
class GenerationResource extends JsonResource
{
    /**
     * Money is formatted here so the browser never does arithmetic on it.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $generation = $this->resource;
        $imageAvailability = $generation->imageAvailability();

        return [
            'id' => $generation->id,
            'status' => $generation->status,
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'estimated_cost' => Money::format($generation->estimated_cost),
            'estimated_cost_max' => Money::format($generation->estimated_cost_max),
            'charged_cost' => $generation->charged_cost === null ? null : Money::format($generation->charged_cost),
            'charged_cost_exact' => $generation->charged_cost === null ? null : Money::formatExact($generation->charged_cost),
            'error_message' => $generation->error_message,
            'image_availability' => $imageAvailability,
            'image_url' => $imageAvailability === ImageAvailability::READY ? route('generations.image', $generation) : null,
            'is_active' => ! $generation->status->isFinal() || $imageAvailability === ImageAvailability::PREPARING,
            'created_at' => $generation->created_at,
        ];
    }
}
