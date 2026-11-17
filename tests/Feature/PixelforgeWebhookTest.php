<?php

use App\Enums\GenerationStatus;
use App\Enums\WebhookOutcome;
use App\Jobs\DownloadGenerationImage;
use App\Models\Generation;
use App\Models\Webhook;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config(['services.pixelforge.webhook_secret' => 'whsec_test']);
    Queue::fake();
});

/**
 * @param  array<string, mixed>  $object
 */
function postEvent(string $id, string $type, array $object, ?string $secret = 'whsec_test', ?int $timestamp = null): TestResponse
{
    $body = json_encode(['id' => $id, 'type' => $type, 'created_at' => now()->toIso8601String(), 'data' => ['object' => $object]]);
    $timestamp ??= time();
    $signature = "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", (string) $secret);

    return test()->call('POST', route('webhooks.pixelforge'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => $signature,
    ], $body);
}

/**
 * @return array<string, mixed>
 */
function succeededObject(Generation $generation, string $reference = 'gen_1'): array
{
    return [
        'id' => $reference,
        'status' => 'succeeded',
        'metadata' => ['generation_id' => $generation->id],
        'usage' => ['input_text_tokens' => 100, 'input_image_tokens' => 1530, 'output_tokens' => 6450, 'reasoning_tokens' => 4000],
        'completed_at' => now()->toIso8601String(),
    ];
}

it('rejects unsigned, mis-signed and stale requests without storing anything', function (?string $secret, ?int $timestamp) {
    $generation = Generation::factory()->create();

    postEvent('evt_1', 'generation.succeeded', succeededObject($generation), $secret, $timestamp)->assertUnauthorized();

    expect(Webhook::count())->toBe(0)
        ->and($generation->refresh()->charged_cost)->toBeNull();
})->with([
    'wrong secret' => ['whsec_wrong', null],
    'stale timestamp' => ['whsec_test', time() - 301],
]);

it('charges the exact cost plus markup when a generation succeeds', function () {
    $generation = Generation::factory()->create(['provider_reference' => 'gen_1']);

    postEvent('evt_1', 'generation.succeeded', succeededObject($generation))->assertNoContent();

    $generation->refresh();

    // 100*5000 + 1530*10000 + 6450*40000 + 4000*12500 nanodollars, then +25%.
    expect($generation->status)->toBe(GenerationStatus::SUCCEEDED)
        ->and($generation->provider_cost)->toBe(323_800_000)
        ->and($generation->charged_cost)->toBe(404_750_000)
        ->and($generation->output_tokens)->toBe(6450)
        ->and(Webhook::sole()->outcome)->toBe(WebhookOutcome::HANDLED);
});

it('prices with the rates Pixelforge actually billed, not our creation-time snapshot', function () {
    $generation = Generation::factory()->create(['provider_reference' => 'gen_1']);
    $object = succeededObject($generation) + ['rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '50.00', 'reasoning_tokens' => '12.50']];

    postEvent('evt_1', 'generation.succeeded', $object)->assertNoContent();

    // Output tokens now cost 50000 nanodollars each: 500_000 + 15_300_000 + 322_500_000 + 50_000_000.
    expect($generation->refresh()->provider_cost)->toBe(388_300_000)
        ->and($generation->rates['output'])->toBe(50000);
});

it('warns when finished usage differs from the published price list, but still charges', function () {
    Http::fake(['*/pricing' => Http::response(file_get_contents(__DIR__.'/../Fixtures/pixelforge-pricing.json'), 200, ['Content-Type' => 'application/json'])]);
    Log::shouldReceive('warning')->once()->withArgs(fn (string $message, array $context) => str_contains($context['drift'][0], 'output tokens 6450'));

    $generation = Generation::factory()->create(['provider_reference' => 'gen_1']); // factory: 1024x1024 medium => 1100 expected

    postEvent('evt_1', 'generation.succeeded', succeededObject($generation))->assertNoContent();

    expect($generation->refresh()->charged_cost)->toBe(404_750_000);
});

it('still charges when the price list cannot be fetched for the drift check', function () {
    Http::fake(['*/pricing' => Http::response('boom', 500)]);
    $generation = Generation::factory()->create(['provider_reference' => 'gen_1']);

    postEvent('evt_1', 'generation.succeeded', succeededObject($generation))->assertNoContent();

    expect($generation->refresh()->charged_cost)->toBe(404_750_000);
});

it('records a duplicate delivery once and never charges twice', function () {
    $generation = Generation::factory()->create(['provider_reference' => 'gen_1']);

    postEvent('evt_1', 'generation.succeeded', succeededObject($generation))->assertNoContent();
    $charged = $generation->refresh()->charged_cost;

    postEvent('evt_1', 'generation.succeeded', succeededObject($generation))->assertNoContent();

    expect(Webhook::count())->toBe(1)
        ->and($generation->refresh()->charged_cost)->toBe($charged);
    Queue::assertPushed(DownloadGenerationImage::class, 1);
});

it('does not charge twice when two different events both report success', function () {
    $generation = Generation::factory()->create(['provider_reference' => 'gen_1']);

    postEvent('evt_1', 'generation.succeeded', succeededObject($generation))->assertNoContent();
    $updatedAt = $generation->refresh()->updated_at;

    postEvent('evt_2', 'generation.succeeded', succeededObject($generation))->assertNoContent();

    expect($generation->refresh()->charged_cost)->toBe(404_750_000)
        ->and($generation->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and(Webhook::where('external_id', 'evt_2')->sole()->outcome)->toBe(WebhookOutcome::IGNORED);
});

it('ignores an out-of-order processing event after success', function () {
    $generation = Generation::factory()->create(['provider_reference' => 'gen_1']);

    postEvent('evt_2', 'generation.succeeded', succeededObject($generation))->assertNoContent();
    postEvent('evt_1', 'generation.processing', ['id' => 'gen_1', 'status' => 'processing', 'metadata' => []])->assertNoContent();

    expect($generation->refresh()->status)->toBe(GenerationStatus::SUCCEEDED)
        ->and(Webhook::where('external_id', 'evt_1')->sole()->outcome)->toBe(WebhookOutcome::IGNORED);
});

it('finds the generation via metadata when the webhook beats the stored Pixelforge id', function () {
    $generation = Generation::factory()->create(['status' => GenerationStatus::PENDING, 'provider_reference' => null]);

    postEvent('evt_1', 'generation.processing', ['id' => 'gen_9', 'status' => 'processing', 'metadata' => ['generation_id' => $generation->id]])->assertNoContent();

    expect($generation->refresh()->status)->toBe(GenerationStatus::PROCESSING)
        ->and($generation->provider_reference)->toBe('gen_9');
});

it('marks a failed generation failed and free', function () {
    $generation = Generation::factory()->create(['provider_reference' => 'gen_1']);

    postEvent('evt_1', 'generation.failed', ['id' => 'gen_1', 'status' => 'failed', 'metadata' => [], 'error' => ['code' => 'generation_failed', 'message' => 'No image']])->assertNoContent();

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::FAILED)
        ->and($generation->error_code)->toBe('generation_failed')
        ->and($generation->provider_cost)->toBeNull()
        ->and($generation->charged_cost)->toBeNull();
});

it('acknowledges pings and unknown generations without error', function () {
    postEvent('evt_ping', 'ping', [])->assertNoContent();
    postEvent('evt_x', 'generation.processing', ['id' => 'gen_nope', 'status' => 'processing', 'metadata' => []])->assertNoContent();

    expect(Webhook::pluck('outcome')->unique()->all())->toBe([WebhookOutcome::IGNORED]);
});

it('fails loudly and never charges a succeeded event with no usage', function () {
    $generation = Generation::factory()->create(['provider_reference' => 'gen_1']);

    postEvent('evt_1', 'generation.succeeded', ['id' => 'gen_1', 'status' => 'succeeded', 'metadata' => []])->assertServerError();

    expect($generation->refresh()->charged_cost)->toBeNull()
        ->and($generation->status)->toBe(GenerationStatus::QUEUED)
        ->and(Webhook::sole()->outcome)->toBe(WebhookOutcome::FAILED);
});
