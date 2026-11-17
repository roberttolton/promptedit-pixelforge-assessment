<?php

namespace App\Enums;

enum GenerationSyncResult: string
{
    case APPLIED = 'applied';

    /** Valid, but doesn't advance the Generation state: the event is older than, or equal to, its current state. */
    case STALE = 'stale';

    case UNKNOWN_GENERATION = 'unknown-generation';
}
