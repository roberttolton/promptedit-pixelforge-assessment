<?php

namespace App\Models;

use App\Enums\Provider;
use App\Enums\WebhookOutcome;
use Database\Factories\WebhookFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $external_id
 * @property Provider $provider
 * @property string $type Raw provider event name; cast with that provider's enum.
 * @property string|null $generation_id
 * @property array<string, mixed> $payload
 * @property WebhookOutcome $outcome
 * @property string|null $failure_reason
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Guarded([])]
class Webhook extends Model
{
    /** @use HasFactory<WebhookFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'payload' => 'array',
            'outcome' => WebhookOutcome::class,
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Generation, $this> */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
