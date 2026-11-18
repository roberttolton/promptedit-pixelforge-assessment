<?php

namespace App\Http\Controllers;

use App\Credits\Ledger;
use App\Enums\CreditPack;
use App\Http\Requests\PurchaseCreditsRequest;
use App\Models\CreditTransaction;
use App\Pricing\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CreditController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('credits/Index', [
            'purchase_id' => (string) Str::uuid(),
            'packs' => array_map(fn (CreditPack $pack) => ['value' => $pack->value, 'label' => $pack->label()], CreditPack::cases()),
            'transactions' => $request->user()->creditTransactions()->latest()->limit(20)->get()->map(fn (CreditTransaction $transaction) => [
                'id' => $transaction->id,
                'type' => $transaction->type->value,
                'amount' => Money::format($transaction->amount),
                'amount_exact' => Money::formatExact(abs($transaction->amount)),
                'created_at' => $transaction->created_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * A fake purchase: no payment is taken, the credits are simply added.
     */
    public function store(PurchaseCreditsRequest $request, Ledger $ledger): RedirectResponse
    {
        $pack = $request->pack();

        $added = $ledger->purchase($request->user(), $pack, $request->validated('purchase_id'));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $added ? __(':amount of credits added.', ['amount' => $pack->label()]) : __('That purchase was already completed.'),
        ]);

        return to_route('credits.index');
    }
}
