<?php

namespace App\Enums;

enum WebhookOutcome: string
{
    case RECEIVED = 'received';

    case HANDLED = 'handled';

    case IGNORED = 'ignored';

    case FAILED = 'failed';
}
