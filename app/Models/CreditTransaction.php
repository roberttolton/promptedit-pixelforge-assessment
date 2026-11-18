<?php

namespace App\Models;

use App\Enums\CreditTransactionType;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property CreditTransactionType $type
 * @property int $amount Signed nanodollars: purchases positive, charges negative.
 * @property string|null $generation_id
 * @property string $idempotency_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Guarded([])]
class CreditTransaction extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CreditTransactionType::class,
            'amount' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
