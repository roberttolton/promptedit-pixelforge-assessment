<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('webhooks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider');
            $table->string('external_id');
            // Provider-specific event name; cast by that provider's own enum.
            $table->string('type');
            // Nullable: an event can arrive before we know the generation.
            $table->foreignUuid('generation_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload');
            $table->string('outcome')->index();
            $table->text('failure_reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhooks');
    }
};
