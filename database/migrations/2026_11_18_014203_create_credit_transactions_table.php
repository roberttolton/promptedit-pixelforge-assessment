<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * The ledger is the record; users.balance is its running total, kept in step inside the
     * same transaction. Amounts are signed integer nanodollars: purchases positive, charges negative.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->bigInteger('balance')->default(0)->after('password');
        });

        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->bigInteger('amount');
            $table->foreignUuid('generation_id')->nullable()->constrained()->nullOnDelete();

            // "purchase:{uuid}" or "charge:{generation uuid}": the same event can never be recorded twice.
            $table->string('idempotency_key')->unique();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        $this->backfillChargesForExistingGenerations();
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('balance');
        });
    }

    /**
     * Generations charged before credits existed become charge entries, so the ledger
     * and the balances account for every charge already made.
     */
    private function backfillChargesForExistingGenerations(): void
    {
        DB::table('generations')->whereNotNull('charged_cost')->orderBy('created_at')->each(function (object $generation): void {
            DB::table('credit_transactions')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $generation->user_id,
                'type' => 'charge',
                'amount' => -$generation->charged_cost,
                'generation_id' => $generation->id,
                'idempotency_key' => "charge:{$generation->id}",
                'created_at' => $generation->completed_at ?? $generation->updated_at,
                'updated_at' => $generation->completed_at ?? $generation->updated_at,
            ]);

            DB::table('users')->where('id', $generation->user_id)->decrement('balance', $generation->charged_cost);
        });
    }
};
