<?php

namespace App\Http\Controllers;

use App\Actions\CreateGeneration;
use App\Credits\Ledger;
use App\Http\Requests\StoreGenerationRequest;
use App\Pricing\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use UnexpectedValueException;

class GenerationEstimateController extends Controller
{
    public function __invoke(StoreGenerationRequest $request, CreateGeneration $createGeneration, Ledger $ledger): JsonResponse
    {
        try {
            $estimate = $createGeneration->estimate(
                $request->validated('prompt'),
                $request->imageSize(),
                $request->imageQuality(),
                $request->referenceImages(),
            );
        } catch (ConnectionException|RequestException|UnexpectedValueException) {
            return response()->json(['message' => __('Pricing is temporarily unavailable.')], 503);
        }

        $available = $ledger->available($request->user()->refresh());

        return response()->json([
            'estimate' => array_map(Money::format(...), $estimate),
            'available' => Money::format($available),
            'can_afford' => $available >= $estimate['max'],
        ]);
    }
}
