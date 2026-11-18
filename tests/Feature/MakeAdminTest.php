<?php

use App\Models\User;

it('makes an existing user an admin', function () {
    $user = User::factory()->create(['email' => 'ops@example.com']);

    $this->artisan('users:make-admin', ['email' => 'ops@example.com'])
        ->expectsOutputToContain('ops@example.com is now an admin.')
        ->assertSuccessful();

    expect($user->refresh()->is_admin)->toBeTrue();
});

it('fails for an email that has no user', function () {
    $this->artisan('users:make-admin', ['email' => 'nobody@example.com'])
        ->expectsOutputToContain('No user has that email address.')
        ->assertFailed();
});

it('does not let a user make themselves an admin through mass assignment', function () {
    $user = User::factory()->create();

    $user->update(['is_admin' => true]);

    expect($user->refresh()->is_admin)->toBeFalse();
});
