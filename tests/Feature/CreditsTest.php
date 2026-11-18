<?php

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

it('redirects guests to the login page', function (string $method, string $route) {
    $this->{$method}(route($route))->assertRedirect(route('login'));
})->with([
    'credits page' => ['get', 'credits.index'],
    'buying credits' => ['post', 'credits.store'],
]);

describe('buying credits', function () {
    it('adds the pack to the balance and records one purchase entry', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('credits.store'), ['pack' => 'ten-dollars', 'purchase_id' => (string) Str::uuid()])
            ->assertRedirect(route('credits.index'));

        $transaction = CreditTransaction::sole();

        expect($user->refresh()->balance)->toBe(10_000_000_000)
            ->and($transaction->type)->toBe(CreditTransactionType::PURCHASE)
            ->and($transaction->amount)->toBe(10_000_000_000)
            ->and($transaction->user_id)->toBe($user->id);
    });

    it('adds credits only once when the same purchase is submitted twice', function () {
        $user = User::factory()->create();
        $purchaseId = (string) Str::uuid();

        $this->actingAs($user)->post(route('credits.store'), ['pack' => 'five-dollars', 'purchase_id' => $purchaseId]);
        $this->actingAs($user)->post(route('credits.store'), ['pack' => 'five-dollars', 'purchase_id' => $purchaseId]);

        expect($user->refresh()->balance)->toBe(5_000_000_000)
            ->and(CreditTransaction::count())->toBe(1);
    });

    it('treats two different purchases as two purchases', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('credits.store'), ['pack' => 'five-dollars', 'purchase_id' => (string) Str::uuid()]);
        $this->actingAs($user)->post(route('credits.store'), ['pack' => 'twenty-five-dollars', 'purchase_id' => (string) Str::uuid()]);

        expect($user->refresh()->balance)->toBe(30_000_000_000);
    });

    it('rejects an invalid pack or purchase id and adds nothing', function (array $input, string $field, string $message) {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('credits.store'), $input)->assertInvalid([$field => $message]);

        expect($user->refresh()->balance)->toBe(0)
            ->and(CreditTransaction::count())->toBe(0);
    })->with([
        'an unknown pack' => [['pack' => 'free-money', 'purchase_id' => 'ignored'], 'pack', 'The selected pack is invalid.'],
        'no pack' => [['purchase_id' => 'ignored'], 'pack', 'The pack field is required.'],
        'a purchase id that is not a uuid' => [['pack' => 'five-dollars', 'purchase_id' => 'abc'], 'purchase_id', 'The purchase id field must be a valid UUID.'],
    ]);
});

describe('credits page', function () {
    it('shows the packs and only the signed-in user\'s transactions, newest first', function () {
        $this->freezeTime();
        $user = User::factory()->create();
        CreditTransaction::create(['user_id' => $user->id, 'type' => CreditTransactionType::PURCHASE, 'amount' => 5_000_000_000, 'idempotency_key' => 'purchase:a', 'created_at' => now()->subHour()]);
        CreditTransaction::create(['user_id' => $user->id, 'type' => CreditTransactionType::CHARGE, 'amount' => -70_912_500, 'idempotency_key' => 'charge:b', 'created_at' => now()]);
        CreditTransaction::create(['user_id' => User::factory()->create()->id, 'type' => CreditTransactionType::PURCHASE, 'amount' => 1, 'idempotency_key' => 'purchase:other']);

        $this->actingAs($user)->get(route('credits.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('credits/Index')
                ->where('packs.0', ['value' => 'five-dollars', 'label' => '$5'])
                ->has('transactions', 2)
                ->where('transactions.0.type', 'charge')
                ->where('transactions.0.amount', '-$0.0709')
                ->where('transactions.0.amount_exact', '$0.070912500')
                ->where('transactions.1.type', 'purchase')
                ->where('transactions.1.amount', '$5.0000')
                ->etc());
    });
});
