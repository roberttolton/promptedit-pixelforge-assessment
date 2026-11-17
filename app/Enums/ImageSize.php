<?php

namespace App\Enums;

enum ImageSize: string
{
    case SQUARE = '1024x1024';

    case LANDSCAPE = '1536x1024';

    case PORTRAIT = '1024x1536';
}
