<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\Provider;
use App\Enums\WebhookOutcome;
use App\Http\Controllers\Controller;
use App\Integrations\Pixelforge\ProcessWebhook;
use App\Integrations\Pixelforge\WebhookSignature;
use App\Models\Webhook;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, ProcessWebhook $process): Response
    {
        $body = $request->getContent();

        abort_unless(
            WebhookSignature::isValid($body, $request->header('Pixelforge-Signature'), (string) config('services.pixelforge.webhook_secret')),
            401,
        );

        $event = json_decode($body, true);

        abort_unless(is_array($event) && is_string($event['id'] ?? null) && is_string($event['type'] ?? null), 400);

        $webhook = Webhook::createOrFirst(
            [
                'provider' => Provider::PIXELFORGE,
                'external_id' => $event['id'],
            ],
            [
                'type' => $event['type'],
                'payload' => $event,
                'outcome' => WebhookOutcome::RECEIVED,
            ],
        );

        $process($webhook);

        return response()->noContent();
    }
}
