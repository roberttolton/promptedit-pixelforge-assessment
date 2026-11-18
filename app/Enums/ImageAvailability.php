<?php

namespace App\Enums;

enum ImageAvailability: string
{
    case READY = 'ready';

    /** Succeeded, but the image has not been copied to storage yet. */
    case PREPARING = 'preparing';

    /** Succeeded, but the copy failed or the Pixelforge URL has expired. */
    case UNAVAILABLE = 'unavailable';

}
