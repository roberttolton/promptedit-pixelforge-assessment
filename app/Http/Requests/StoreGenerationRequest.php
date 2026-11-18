<?php

namespace App\Http\Requests;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGenerationRequest extends FormRequest
{
    /**
     * Mirrors the Pixelforge limits: 4,000 character prompt and up to 4 http(s) reference images.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::enum(ImageSize::class)],
            'quality' => ['required', Rule::enum(ImageQuality::class)],
            'reference_images' => ['array', 'max:4'],
            'reference_images.*' => ['string', 'url:http,https', 'max:2048'],
        ];
    }

    public function imageSize(): ImageSize
    {
        return $this->enum('size', ImageSize::class);
    }

    public function imageQuality(): ImageQuality
    {
        return $this->enum('quality', ImageQuality::class);
    }

    /**
     * @return list<string>
     */
    public function referenceImages(): array
    {
        return array_values($this->validated('reference_images', []));
    }
}
