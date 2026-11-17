<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Enums\Provider;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Money columns are integer nanodollars (1 USD = 1_000_000_000).
 *
 * @property string $id
 * @property string $user_id
 * @property Provider $provider
 * @property string|null $provider_reference
 * @property GenerationStatus $status
 * @property string $prompt
 * @property ImageSize $size
 * @property ImageQuality $quality
 * @property list<string> $reference_images
 * @property int $markup_basis_points
 * @property array{input_text: int, input_image: int, output: int, reasoning: int} $rates
 * @property int $estimated_cost
 * @property int $estimated_cost_max
 * @property int|null $provider_cost
 * @property int|null $charged_cost
 * @property int|null $input_text_tokens
 * @property int|null $input_image_tokens
 * @property int|null $output_tokens
 * @property int|null $reasoning_tokens
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string|null $image_path
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Guarded([])]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'status' => GenerationStatus::class,
            'size' => ImageSize::class,
            'quality' => ImageQuality::class,
            'reference_images' => 'array',
            'rates' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Webhook, $this> */
    public function webhooks(): HasMany
    {
        return $this->hasMany(Webhook::class);
    }

    public function failIfPending(string $code, string $message): void
    {
        static::whereKey($this->getKey())
            ->where('status', GenerationStatus::PENDING)
            ->update([
                'status' => GenerationStatus::FAILED,
                'error_code' => $code,
                'error_message' => $message,
                'completed_at' => now(),
            ]);
    }
}
