<?php

use App\Models\Webhook;
use Illuminate\Database\UniqueConstraintViolationException;

it('rejects a second webhook with the same provider and external id', function () {
    Webhook::factory()->create(['external_id' => 'evt_1']);

    Webhook::factory()->create(['external_id' => 'evt_1']);
})->throws(UniqueConstraintViolationException::class);
