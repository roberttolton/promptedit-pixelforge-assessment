<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('allows the owner to view their generation', function () {
    $owner = User::factory()->create();
    $generation = Generation::factory()->for($owner)->make();

    expect(Gate::forUser($owner)->inspect('view', $generation)->allowed())->toBeTrue();
});

it('answers not found, not forbidden, for another user\'s generation', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $generation = Generation::factory()->for($owner)->make();

    $response = Gate::forUser($stranger)->inspect('view', $generation);

    expect($response->denied())->toBeTrue()
        ->and($response->status())->toBe(404);
});
