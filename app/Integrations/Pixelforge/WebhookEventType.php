<?php

namespace App\Integrations\Pixelforge;

enum WebhookEventType: string
{
    case PING = 'ping';

    case GENERATION_PROCESSING = 'generation.processing';

    case GENERATION_SUCCEEDED = 'generation.succeeded';

    case GENERATION_FAILED = 'generation.failed';
}
