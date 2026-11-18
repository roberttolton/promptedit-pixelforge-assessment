<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

it('redirects guests to the login page', function () {
    $generation = Generation::factory()->withImage()->create();

    $this->get(route('generations.image', $generation))->assertRedirect(route('login'));
});

it('redirects the owner to a ten minute signed url for the stored image', function () {
    $this->travelTo('2026-01-01 12:00:00');
    $owner = User::factory()->create();
    $generation = Generation::factory()->for($owner)->withImage()->create();
    Storage::fake();
    Storage::disk()->buildTemporaryUrlsUsing(
        fn (string $path, $expiration) => "https://bucket.test/{$path}?expires={$expiration->getTimestamp()}",
    );
    $expires = Carbon::parse('2026-01-01 12:10:00')->getTimestamp();

    $response = $this->actingAs($owner)->get(route('generations.image', $generation));

    $response->assertRedirect("https://bucket.test/{$generation->image_path}?expires={$expires}");
});

it('returns 404 when the image has not been stored', function () {
    $owner = User::factory()->create();
    $generation = Generation::factory()->for($owner)->succeeded()->create();

    $this->actingAs($owner)->get(route('generations.image', $generation))->assertNotFound();
});

it('returns 404 for another user\'s image', function () {
    $stranger = User::factory()->create();
    $generation = Generation::factory()->withImage()->create();
    Storage::fake();
    Storage::put($generation->image_path, 'PNGBYTES');

    $this->actingAs($stranger)->get(route('generations.image', $generation))->assertNotFound();
});
