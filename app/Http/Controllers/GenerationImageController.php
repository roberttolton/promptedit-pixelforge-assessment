<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;

class GenerationImageController extends Controller
{
    /**
     * Images are private, so the browser is sent to a short-lived signed URL: the bucket's
     * on Laravel Cloud, or the signed local route in development.
     */
    public function __invoke(Generation $generation): RedirectResponse
    {
        abort_if($generation->image_path === null, 404);

        return redirect()->away(Storage::temporaryUrl($generation->image_path, now()->addMinutes(10)));
    }
}
