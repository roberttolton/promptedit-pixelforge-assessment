<?php

namespace App\Credits;

use App\Enums\CreditPack;
use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Every credit movement is a signed ledger row, written in the same transaction as the
 * change to users.balance, and keyed so the same event can never be recorded twice.
 */
class Ledger
{
    /**
     * A repeated purchase id (a double-click, a retried request) adds nothing.
     *
     * @return bool Whether credits were added.
     */
    public function purchase(User $user, CreditPack $pack, string $purchaseId): bool
    {
        return DB::transaction(function () use ($user, $pack, $purchaseId): bool {
            $this->lock($user);

            $key = "purchase:{$purchaseId}";

            if (CreditTransaction::where('idempotency_key', $key)->exists()) {
                return false;
            }

            $this->record($user->id, CreditTransactionType::PURCHASE, $pack->nanodollars(), $key);

            return true;
        });
    }

    /**
     * Called inside SyncGeneration's transaction, at the one moment a generation is
     * charged, so a charge and its debit can only exist together.
     */
    public function charge(Generation $generation): void
    {
        $this->record(
            $generation->user_id,
            CreditTransactionType::CHARGE,
            -$generation->charged_cost,
            "charge:{$generation->id}",
            $generation->id,
        );
    }

    /**
     * Balance minus what the user's unfinished generations could still cost (their estimated
     * maximum), so several submissions at once cannot spend the same credits. Call with the
     * user row locked.
     */
    public function available(User $user): int
    {
        $reserved = (int) Generation::where('user_id', $user->id)
            ->whereNotIn('status', [GenerationStatus::SUCCEEDED, GenerationStatus::FAILED])
            ->sum('estimated_cost_max');

        return $user->balance - $reserved;
    }

    /**
     * Locks the user's row for the rest of the transaction and returns it fresh, so
     * concurrent purchases and submissions for one user are processed one at a time.
     */
    public function lock(User $user): User
    {
        return User::whereKey($user->id)->lockForUpdate()->firstOrFail();
    }

    private function record(string $userId, CreditTransactionType $type, int $amount, string $key, ?string $generationId = null): void
    {
        CreditTransaction::create([
            'user_id' => $userId,
            'type' => $type,
            'amount' => $amount,
            'generation_id' => $generationId,
            'idempotency_key' => $key,
        ]);

        User::whereKey($userId)->increment('balance', $amount);
    }
}
