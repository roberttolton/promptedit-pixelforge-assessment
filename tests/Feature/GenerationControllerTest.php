<?php

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

it('redirects guests to the login page', function (string $method, string $route) {
    $this->{$method}(route($route))->assertRedirect(route('login'));
})->with([
    'history' => ['get', 'dashboard'],
    'new generation form' => ['get', 'generations.create'],
    'submitting a generation' => ['post', 'generations.store'],
]);

describe('history', function () {
    it('lists only the signed-in user\'s generations, newest first', function () {
        $this->freezeTime();
        $user = User::factory()->create();
        [$older, $newer] = Generation::factory()->for($user)->count(2)
            ->sequence(['created_at' => now()->subHour()], ['created_at' => now()])
            ->create();
        Generation::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('generations/Index')
            ->has('generations.data', 2)
            ->where('generations.data.0.id', $newer->id)
            ->where('generations.data.1.id', $older->id));
    });

    it('paginates ten generations at a time', function () {
        $user = User::factory()->create();
        Generation::factory()->for($user)->count(11)->create();

        $first = $this->actingAs($user)->get(route('dashboard'));
        $second = $this->actingAs($user)->get(route('dashboard', ['page' => 2]));

        $first->assertInertia(fn (Assert $page) => $page
            ->has('generations.data', 10)
            ->where('generations.meta.last_page', 2));
        $second->assertInertia(fn (Assert $page) => $page->has('generations.data', 1));
    });

    it('formats money for display and keeps the exact amount for the tooltip', function () {
        $user = User::factory()->create();
        Generation::factory()->for($user)->charged(404_750_000)->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.0.estimated_cost', '$0.0500')
            ->where('generations.data.0.estimated_cost_max', '$0.1000')
            ->where('generations.data.0.charged_cost', '$0.4048')
            ->where('generations.data.0.charged_cost_exact', '$0.404750000')
            ->etc());
    });

    it('exposes the image route only once the image is stored', function () {
        $user = User::factory()->create();
        $generation = Generation::factory()->for($user)->withImage()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.0.image_url', route('generations.image', $generation))
            ->where('generations.data.0.image_availability', 'ready')
            ->where('generations.data.0.is_active', false)
            ->etc());
    });

    it('keeps polling a generation that succeeded but has no image yet', function () {
        $user = User::factory()->create();
        Generation::factory()->for($user)->succeeded()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.0.image_url', null)
            ->where('generations.data.0.image_availability', 'preparing')
            ->where('generations.data.0.is_active', true)
            ->etc());
    });

    it('shows why a generation failed and stops polling it', function () {
        $user = User::factory()->create();
        Generation::factory()->for($user)->failed('No image could be produced.')->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.0.status', 'failed')
            ->where('generations.data.0.error_message', 'No image could be produced.')
            ->where('generations.data.0.charged_cost', null)
            ->where('generations.data.0.is_active', false)
            ->etc());
    });
});

describe('new generation form', function () {
    it('renders the form with the size and quality options', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('generations.create'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('generations/Create')
            ->where('sizes', [
                ['value' => '1024x1024', 'label' => 'Square (1024 × 1024)'],
                ['value' => '1536x1024', 'label' => 'Landscape (1536 × 1024)'],
                ['value' => '1024x1536', 'label' => 'Portrait (1024 × 1536)'],
            ])
            ->where('qualities', [
                ['value' => 'low', 'label' => 'Low'],
                ['value' => 'medium', 'label' => 'Medium'],
                ['value' => 'high', 'label' => 'High'],
            ]));
    });
});

describe('submitting a generation', function () {
    it('saves a pending generation with its estimate, queues the submission and redirects to the history', function () {
        $user = User::factory()->withCredits(1_000_000_000)->create();
        fakePricingEndpoint();
        Queue::fake(SubmitGeneration::class);

        $response = $this->actingAs($user)->post(route('generations.store'), [
            'prompt' => 'A lighthouse',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/a.png'],
        ]);

        $response->assertRedirect(route('dashboard'));
        $generation = $user->generations()->sole();
        // Provider cost 0.321915 expected / 0.365670 max USD, plus 25%.
        expect($generation)
            ->status->toBe(GenerationStatus::PENDING)
            ->prompt->toBe('A lighthouse')
            ->size->toBe(ImageSize::LANDSCAPE)
            ->quality->toBe(ImageQuality::HIGH)
            ->reference_images->toBe(['https://example.com/a.png'])
            ->markup_basis_points->toBe(2500)
            ->estimated_cost->toBe(402_393_750)
            ->estimated_cost_max->toBe(457_087_500);
        Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generation->is($generation));
    });

    it('rejects an empty submission and names every required field', function () {
        $user = User::factory()->create();
        Queue::fake(SubmitGeneration::class);

        $response = $this->actingAs($user)->post(route('generations.store'), []);

        $response->assertInvalid([
            'prompt' => 'The prompt field is required.',
            'size' => 'The size field is required.',
            'quality' => 'The quality field is required.',
        ]);
        $this->assertDatabaseCount('generations', 0);
        Queue::assertNothingPushed();
    });

    it('rejects invalid input with a clear message and creates nothing', function (array $overrides, string $field, string $message) {
        $user = User::factory()->create();
        Queue::fake(SubmitGeneration::class);

        $response = $this->actingAs($user)->post(route('generations.store'), $overrides + [
            'prompt' => 'A lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
        ]);

        $response->assertInvalid([$field => $message]);
        $this->assertDatabaseCount('generations', 0);
        Queue::assertNothingPushed();
    })->with([
        'a prompt over 4000 characters' => [['prompt' => str_repeat('a', 4001)], 'prompt', 'The prompt field must not be greater than 4000 characters.'],
        'an unknown size' => [['size' => '1x1'], 'size', 'The selected size is invalid.'],
        'an unknown quality' => [['quality' => 'ultra'], 'quality', 'The selected quality is invalid.'],
        'more than four reference images' => [['reference_images' => array_fill(0, 5, 'https://example.com/a.png')], 'reference_images', 'The reference images field must not have more than 4 items.'],
        'a reference image that is not a url' => [['reference_images' => ['not-a-url']], 'reference_images.0', 'The reference_images.0 field must be a valid URL.'],
        'a reference image with a non-http scheme' => [['reference_images' => ['ftp://example.com/a.png']], 'reference_images.0', 'The reference_images.0 field must be a valid URL.'],
    ]);

    it('explains that pricing is unavailable and creates nothing', function () {
        $user = User::factory()->create();
        Http::fake(['*/pricing' => Http::response('boom', 500)]);
        Queue::fake(SubmitGeneration::class);

        $response = $this->actingAs($user)->post(route('generations.store'), [
            'prompt' => 'A lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
        ]);

        $response->assertInvalid(['pricing' => 'Pricing is temporarily unavailable, so nothing was submitted. Please try again in a moment.']);
        $this->assertDatabaseCount('generations', 0);
        Queue::assertNothingPushed();
    });

    it('refuses to submit when the credits cannot cover the most it could cost, and creates nothing', function () {
        $user = User::factory()->withCredits(457_087_499)->create();
        fakePricingEndpoint();
        Queue::fake(SubmitGeneration::class);

        $response = $this->actingAs($user)->post(route('generations.store'), [
            'prompt' => 'A lighthouse',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/a.png'],
        ]);

        // The maximum is 457_087_500 nanodollars; one nanodollar short is not enough.
        $response->assertInvalid(['credits' => 'Not enough credits: this could cost up to $0.4571 and you have $0.4571 available. Buy credits to continue.']);
        $this->assertDatabaseCount('generations', 0);
        Queue::assertNothingPushed();
    });

    it('accepts a submission when the credits exactly cover the most it could cost', function () {
        $user = User::factory()->withCredits(457_087_500)->create();
        fakePricingEndpoint();
        Queue::fake(SubmitGeneration::class);

        $this->actingAs($user)->post(route('generations.store'), [
            'prompt' => 'A lighthouse',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/a.png'],
        ])->assertRedirect(route('dashboard'));

        expect($user->generations()->count())->toBe(1);
    });

    it('counts generations still in progress against the credits, but not finished ones', function () {
        $user = User::factory()->withCredits(500_000_000)->create();
        fakePricingEndpoint();
        Queue::fake(SubmitGeneration::class);
        // An unfinished generation could still cost up to 100_000_000 (the factory default); finished ones hold nothing.
        Generation::factory()->for($user)->create(['status' => GenerationStatus::PROCESSING, 'estimated_cost_max' => 100_000_000]);
        Generation::factory()->for($user)->succeeded()->create(['estimated_cost_max' => 900_000_000]);
        Generation::factory()->for($user)->failed()->create(['estimated_cost_max' => 900_000_000]);
        Generation::factory()->create(['status' => GenerationStatus::PROCESSING, 'estimated_cost_max' => 900_000_000]);

        $response = $this->actingAs($user)->post(route('generations.store'), [
            'prompt' => 'A lighthouse',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/a.png'],
        ]);

        // 500_000_000 - 100_000_000 = 400_000_000 available, below the 457_087_500 maximum.
        $response->assertInvalid(['credits' => 'Not enough credits: this could cost up to $0.4571 and you have $0.4000 available. Buy credits to continue.']);
    });
});
