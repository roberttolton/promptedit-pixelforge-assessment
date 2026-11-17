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
        Schema::create('generations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_reference')->nullable();
            $table->string('status')->index();

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            // Money: integer nanodollars. Markup and rates are snapshotted so history never changes.
            $table->unsignedInteger('markup_basis_points');
            $table->json('rates');
            $table->unsignedBigInteger('estimated_cost');
            $table->unsignedBigInteger('estimated_cost_max');
            $table->unsignedBigInteger('provider_cost')->nullable();
            $table->unsignedBigInteger('charged_cost')->nullable();

            $table->unsignedInteger('input_text_tokens')->nullable();
            $table->unsignedInteger('input_image_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('reasoning_tokens')->nullable();

            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->string('image_path')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_reference']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
