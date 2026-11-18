<?php

namespace App\Http\Controllers;

use App\Actions\CreateGeneration;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use UnexpectedValueException;

class GenerationController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('generations/Index', [
            'generations' => GenerationResource::collection(
                $request->user()->generations()->latest()->paginate(10),
            ),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('generations/Create', [
            'sizes' => array_map(fn (ImageSize $size) => ['value' => $size->value, 'label' => $size->label()], ImageSize::cases()),
            'qualities' => array_map(fn (ImageQuality $quality) => ['value' => $quality->value, 'label' => $quality->label()], ImageQuality::cases()),
        ]);
    }

    public function store(StoreGenerationRequest $request, CreateGeneration $createGeneration): RedirectResponse
    {
        try {
            $createGeneration(
                $request->user(),
                $request->validated('prompt'),
                $request->imageSize(),
                $request->imageQuality(),
                $request->referenceImages(),
            );
        } catch (ConnectionException|RequestException|UnexpectedValueException) {
            throw ValidationException::withMessages([
                'pricing' => __('Pricing is temporarily unavailable, so nothing was submitted. Please try again in a moment.'),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Generation submitted.')]);

        return to_route('dashboard');
    }
}
