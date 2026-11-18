<?php

use App\Enums\ImageAvailability;
use App\Models\Generation;

it('has no image availability until the generation has succeeded', function () {
    $generation = Generation::factory()->make();

    expect($generation->imageAvailability())->toBeNull();
});

it('reports the image as ready once it is stored', function () {
    $generation = Generation::factory()->withImage()->make();

    expect($generation->imageAvailability())->toBe(ImageAvailability::READY);
});

it('waits ten minutes for the image copy before calling it unavailable', function (int $minutesSinceSuccess, ImageAvailability $expected) {
    $this->freezeTime();
    $generation = Generation::factory()->succeeded()->make(['completed_at' => now()->subMinutes($minutesSinceSuccess)]);

    expect($generation->imageAvailability())->toBe($expected);
})->with([
    'just succeeded' => [0, ImageAvailability::PREPARING],
    'nine minutes ago' => [9, ImageAvailability::PREPARING],
    'exactly ten minutes ago' => [10, ImageAvailability::UNAVAILABLE],
    'an hour ago' => [60, ImageAvailability::UNAVAILABLE],
]);
