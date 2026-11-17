<?php

use App\Enums\GenerationStatus;

it('only moves forward and allows skipping lost intermediate events', function () {
    expect(GenerationStatus::QUEUED->canTransitionTo(GenerationStatus::SUCCEEDED))->toBeTrue()
        ->and(GenerationStatus::PROCESSING->canTransitionTo(GenerationStatus::QUEUED))->toBeFalse()
        ->and(GenerationStatus::QUEUED->canTransitionTo(GenerationStatus::QUEUED))->toBeFalse();
});

it('never leaves a final status', function (GenerationStatus $final) {
    expect($final->isFinal())->toBeTrue();

    foreach (GenerationStatus::cases() as $next) {
        expect($final->canTransitionTo($next))->toBeFalse();
    }
})->with([GenerationStatus::SUCCEEDED, GenerationStatus::FAILED]);
