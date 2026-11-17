<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

function staleGeneration(array $attributes = []): Generation
{
    return Generation::factory()->create($attributes + ['updated_at' => now()->subMinutes(5)]);
}

it('recovers a generation whose webhooks never arrived by polling it', function () {
    $generation = staleGeneration(['status' => GenerationStatus::PROCESSING, 'provider_reference' => 'gen_1']);

    Http::fake(['*/generations/gen_1' => Http::response([
        'id' => 'gen_1',
        'status' => 'succeeded',
        'metadata' => ['generation_id' => $generation->id],
        'usage' => ['input_text_tokens' => 100, 'input_image_tokens' => 1530, 'output_tokens' => 6450, 'reasoning_tokens' => 4000],
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'completed_at' => now()->toIso8601String(),
    ])]);

    $this->artisan('generations:reconcile')->assertSuccessful();

    expect($generation->refresh()->status)->toBe(GenerationStatus::SUCCEEDED)
        ->and($generation->charged_cost)->toBe(404_750_000);
});

it('leaves recently updated generations alone', function () {
    Generation::factory()->create(['status' => GenerationStatus::PROCESSING, 'provider_reference' => 'gen_1']);

    $this->artisan('generations:reconcile')->assertSuccessful();

    Http::assertNothingSent();
});

it('resubmits a stuck pending generation idempotently', function () {
    $generation = staleGeneration(['status' => GenerationStatus::PENDING, 'provider_reference' => null]);

    $this->artisan('generations:reconcile')->assertSuccessful();

    Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generation->is($generation));
});

it('gives up on a generation that has been pending for over an hour, for free', function () {
    $generation = staleGeneration(['status' => GenerationStatus::PENDING, 'provider_reference' => null, 'created_at' => now()->subHours(2)]);

    $this->artisan('generations:reconcile')->assertSuccessful();

    expect($generation->refresh()->status)->toBe(GenerationStatus::FAILED)
        ->and($generation->error_code)->toBe('submission_failed')
        ->and($generation->charged_cost)->toBeNull();
    Queue::assertNothingPushed();
});

it('stops polling when rate limited instead of hammering the API', function () {
    staleGeneration(['status' => GenerationStatus::QUEUED, 'provider_reference' => 'gen_1']);
    staleGeneration(['status' => GenerationStatus::QUEUED, 'provider_reference' => 'gen_2']);

    Http::fake(['*' => Http::response(['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Slow down']], 429, ['Retry-After' => '30'])]);

    $this->artisan('generations:reconcile')->assertSuccessful();

    Http::assertSentCount(1);
});
