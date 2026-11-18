<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

it('redirects guests to the login page', function () {
    $this->postJson(route('generations.estimate'), [])->assertUnauthorized();
});

it('returns the estimated range with the markup applied', function () {
    $user = User::factory()->create();
    fakePricingEndpoint();

    $response = $this->actingAs($user)->postJson(route('generations.estimate'), [
        'prompt' => str_repeat('a', 400),
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
    ]);

    // Provider cost 0.286225 / 0.330050 / 0.373875 USD, plus 25%, rounded half up to 4 decimals.
    $response->assertOk()->assertExactJson([
        'estimate' => ['min' => '$0.3578', 'expected' => '$0.4126', 'max' => '$0.4673'],
    ]);
});

it('returns 503 with a message when pricing cannot be fetched', function () {
    $user = User::factory()->create();
    Http::fake(['*/pricing' => Http::response('boom', 500)]);

    $response = $this->actingAs($user)->postJson(route('generations.estimate'), [
        'prompt' => 'A lighthouse',
        'size' => '1024x1024',
        'quality' => 'medium',
    ]);

    $response->assertServiceUnavailable()
        ->assertExactJson(['message' => 'Pricing is temporarily unavailable.']);
});

it('rejects an estimate request that fails validation', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('generations.estimate'), [
        'prompt' => 'A lighthouse',
        'size' => '1x1',
        'quality' => 'medium',
    ]);

    $response->assertInvalid(['size' => 'The selected size is invalid.']);
});
