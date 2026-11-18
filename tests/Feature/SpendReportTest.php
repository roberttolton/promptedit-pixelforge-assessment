<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

function fakeUsage(array $costsByDay): void
{
    Http::fake(['*/usage*' => Http::response([
        'object' => 'usage',
        'data' => collect($costsByDay)->map(fn (string $cost, string $date) => ['date' => $date, 'cost_usd' => $cost])->values()->all(),
    ])]);
}

function reportUrl(): string
{
    return route('admin.spend', ['start_date' => '2026-03-01', 'end_date' => '2026-03-02']);
}

/**
 * Day 1: ada has two generations (provider 0.1 + 0.2, charged 0.125 + 0.25), one of which
 * was created on day 2 but completed on day 1. Day 2: grace has one (provider 0.4, charged 0.5).
 */
function seedSpend(): void
{
    test()->travelTo('2026-03-02 15:00:00');

    $ada = User::factory()->create(['name' => 'Ada']);
    $grace = User::factory()->create(['name' => 'Grace']);

    Generation::factory()->for($ada)->succeeded()->create(['provider_cost' => 100_000_000, 'charged_cost' => 125_000_000, 'completed_at' => '2026-03-01 08:00:00']);
    Generation::factory()->for($ada)->succeeded()->create(['provider_cost' => 200_000_000, 'charged_cost' => 250_000_000, 'created_at' => '2026-03-02 00:10:00', 'completed_at' => '2026-03-01 23:59:59']);
    Generation::factory()->for($grace)->succeeded()->create(['provider_cost' => 400_000_000, 'charged_cost' => 500_000_000, 'completed_at' => '2026-03-02 09:00:00']);

    // Not part of the report: failed (free) and outside the requested range.
    Generation::factory()->for($grace)->failed()->create(['completed_at' => '2026-03-02 10:00:00']);
    Generation::factory()->for($grace)->succeeded()->create(['provider_cost' => 900_000_000, 'charged_cost' => 900_000_000, 'completed_at' => '2026-02-28 10:00:00']);
}

it('redirects guests to the login page', function () {
    $this->get(route('admin.spend'))->assertRedirect(route('login'));
});

it('answers 404 to a signed-in user who is not an admin', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.spend'))->assertNotFound();
});

it('reports charged, provider cost and profit per UTC completion day and user', function () {
    seedSpend();
    fakeUsage(['2026-03-01' => '0.300000000', '2026-03-02' => '0.400000000']);

    $this->actingAs(User::factory()->admin()->create())->get(reportUrl())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/SpendReport')
            ->where('pixelforge_available', true)
            ->where('totals.generations', 3)
            ->where('totals.charged_exact', '$0.875000000')
            ->where('totals.provider_exact', '$0.700000000')
            ->where('totals.profit_exact', '$0.175000000')
            ->where('totals.pixelforge_exact', '$0.700000000')
            ->where('totals.matches', true)
            ->has('days', 2)
            ->where('days.0.date', '2026-03-02')
            ->where('days.0.provider_exact', '$0.400000000')
            ->where('days.0.users.0.name', 'Grace')
            ->where('days.0.users.0.profit_exact', '$0.100000000')
            ->where('days.1.date', '2026-03-01')
            ->where('days.1.generations', 2)
            ->where('days.1.charged_exact', '$0.375000000')
            ->where('days.1.provider_exact', '$0.300000000')
            ->where('days.1.profit_exact', '$0.075000000')
            ->where('days.1.matches', true)
            ->where('days.1.users.0.name', 'Ada'));
});

it('flags a day whose provider cost differs from what Pixelforge reports', function () {
    seedSpend();
    fakeUsage(['2026-03-01' => '0.300000000', '2026-03-02' => '0.400000001']);

    $this->actingAs(User::factory()->admin()->create())->get(reportUrl())
        ->assertInertia(fn (Assert $page) => $page
            ->where('days.0.matches', false)
            ->where('days.1.matches', true)
            ->where('totals.matches', false));
});

it('shows the report without the check when Pixelforge usage is unavailable', function () {
    seedSpend();
    Http::fake(['*/usage*' => Http::response('boom', 500)]);

    $this->actingAs(User::factory()->admin()->create())->get(reportUrl())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('pixelforge_available', false)
            ->where('totals.provider_exact', '$0.700000000')
            ->where('totals.matches', null));
});

it('rejects an end date in the future', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.spend', ['end_date' => now()->addDay()->toDateString()]))
        ->assertSessionHasErrors(['end_date' => 'The end date field must be a date before or equal to today.']);
});
