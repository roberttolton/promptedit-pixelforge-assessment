<?php

use App\Http\Controllers\Admin\SpendReportController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\GenerationEstimateController;
use App\Http\Controllers\GenerationImageController;
use App\Http\Controllers\Webhooks\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {

    Route::get('dashboard', [GenerationController::class, 'index'])->name('dashboard');

    Route::get('generations/create', [GenerationController::class, 'create'])->name('generations.create');

    Route::post('generations', [GenerationController::class, 'store'])->name('generations.store');

    Route::post('generations/estimate', GenerationEstimateController::class)
        ->middleware('throttle:60,1')
        ->name('generations.estimate');

    Route::get('generations/{generation}/image', GenerationImageController::class)
        ->can('view', 'generation')
        ->name('generations.image');

});

// Operator pages: every route in this group is admin-only, and anything added later is protected by default.
Route::middleware(['auth', 'verified', 'can:access-admin'])->prefix('admin')->name('admin.')->group(function () {

    Route::get('spend', SpendReportController::class)->name('spend');

});

Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');

require __DIR__.'/settings.php';
