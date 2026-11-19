<?php

use App\Actions\CreateGeneration;
use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Integrations\Pixelforge\PixelforgeClient;
use App\Integrations\Pixelforge\SyncGeneration;
use App\Jobs\DownloadGenerationImage;
use App\Jobs\SubmitGeneration;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function fakePixelforge(array $generationsResponse): void
{
    Http::fake([
        '*/pricing' => Http::response(file_get_contents(__DIR__.'/../Fixtures/pixelforge-pricing.json'), 200, ['Content-Type' => 'application/json']),
        '*/generations' => $generationsResponse[0] ?? Http::response($generationsResponse, 202),
    ]);
}

function createGeneration(string $prompt = 'A lighthouse'): Generation
{
    return app(CreateGeneration::class)(User::factory()->withCredits(10_000_000_000)->create(), $prompt, ImageSize::SQUARE, ImageQuality::MEDIUM);
}

it('stores the estimate and snapshots, then submits idempotently', function () {
    fakePixelforge(['id' => 'gen_abc', 'status' => 'queued']);

    $generation = createGeneration()->refresh();

    expect($generation->status)->toBe(GenerationStatus::QUEUED)
        ->and($generation->provider_reference)->toBe('gen_abc')
        ->and($generation->markup_basis_points)->toBe(2500)
        ->and($generation->rates['output'])->toBe(40000)
        ->and($generation->estimated_cost)->toBeGreaterThan(0)
        ->and($generation->estimated_cost_max)->toBeGreaterThan($generation->estimated_cost);

    Http::assertSent(fn (Request $r) => $r->url() !== '' && str_ends_with($r->url(), '/generations')
        && $r->header('Idempotency-Key')[0] === $generation->id
        && $r['metadata']['generation_id'] === $generation->id);
});

it('does not move a generation backwards if a webhook already advanced it', function () {
    fakePixelforge(['id' => 'gen_abc', 'status' => 'queued']);
    $generation = Generation::factory()->create(['status' => GenerationStatus::PENDING]);

    // A webhook lands while the job is in flight; the job's in-memory copy is stale.
    Generation::whereKey($generation->id)->update(['status' => GenerationStatus::PROCESSING]);

    (new SubmitGeneration($generation))->handle(app(PixelforgeClient::class), app(SyncGeneration::class));

    expect($generation->refresh()->status)->toBe(GenerationStatus::PROCESSING)
        ->and($generation->provider_reference)->toBe('gen_abc');
});

it('charges once when the resubmit is replayed and Pixelforge says it already succeeded', function () {
    Queue::fake([DownloadGenerationImage::class]);
    fakePixelforge([
        'id' => 'gen_abc',
        'status' => 'succeeded',
        'completed_at' => now()->toIso8601String(),
        'usage' => ['input_text_tokens' => 100, 'input_image_tokens' => 0, 'output_tokens' => 1000, 'reasoning_tokens' => 0],
    ]);

    $generation = createGeneration()->refresh();

    expect($generation->status)->toBe(GenerationStatus::SUCCEEDED)
        ->and($generation->provider_cost)->toBeGreaterThan(0)
        ->and($generation->charged_cost)->toBeGreaterThan($generation->provider_cost)
        ->and(CreditTransaction::where('generation_id', $generation->id)->sole()->amount)->toBe(-$generation->charged_cost)
        ->and($generation->user->refresh()->balance)->toBe(10_000_000_000 - $generation->charged_cost);
    Queue::assertPushed(DownloadGenerationImage::class);
});

it('fails for free when the resubmit is replayed and Pixelforge says it already failed', function () {
    fakePixelforge([
        'id' => 'gen_abc',
        'status' => 'failed',
        'error' => ['code' => 'generation_failed', 'message' => 'No image could be produced.'],
    ]);

    $generation = createGeneration()->refresh();

    expect($generation->status)->toBe(GenerationStatus::FAILED)
        ->and($generation->charged_cost)->toBeNull()
        ->and(CreditTransaction::where('generation_id', $generation->id)->count())->toBe(0)
        ->and($generation->user->refresh()->balance)->toBe(10_000_000_000);
});

it('keeps the generation pending when Pixelforge is overloaded', function () {
    fakePixelforge([Http::response(['error' => ['code' => 'overloaded', 'message' => 'Busy']], 503, ['Retry-After' => '2'])]);

    expect(createGeneration()->refresh()->status)->toBe(GenerationStatus::PENDING);
});

it('fails a rejected prompt for free', function () {
    fakePixelforge([Http::response(['error' => ['code' => 'content_policy_violation', 'message' => 'Rejected']], 400)]);

    $generation = createGeneration('[blocked]')->refresh();

    expect($generation->status)->toBe(GenerationStatus::FAILED)
        ->and($generation->error_code)->toBe('content_policy_violation')
        ->and($generation->charged_cost)->toBeNull();
});

it('fails the generation on the last attempt when Pixelforge stays overloaded', function () {
    fakePixelforge([Http::response(['error' => ['code' => 'overloaded', 'message' => 'Busy']], 503)]);
    $generation = Generation::factory()->create(['status' => GenerationStatus::PENDING]);

    $job = (new SubmitGeneration($generation))->withFakeQueueInteractions();
    $job->job->attempts = $job->tries;
    $job->handle(app(PixelforgeClient::class), app(SyncGeneration::class));

    expect($generation->refresh()->status)->toBe(GenerationStatus::FAILED)
        ->and($generation->error_code)->toBe('overloaded')
        ->and($generation->charged_cost)->toBeNull();
});

it('leaves the generation pending on the last attempt after an unexplained server error', function () {
    fakePixelforge([Http::response('oops', 500)]);
    $generation = Generation::factory()->create(['status' => GenerationStatus::PENDING]);

    $job = (new SubmitGeneration($generation))->withFakeQueueInteractions();
    $job->job->attempts = $job->tries;
    $job->handle(app(PixelforgeClient::class), app(SyncGeneration::class));

    expect($generation->refresh()->status)->toBe(GenerationStatus::PENDING);
});
