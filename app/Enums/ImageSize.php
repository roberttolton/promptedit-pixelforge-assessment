<?php

namespace App\Enums;

enum ImageSize: string
{
    case SQUARE = '1024x1024';

    case LANDSCAPE = '1536x1024';

    case PORTRAIT = '1024x1536';

    public function label(): string
    {
        return match ($this) {
            self::SQUARE => 'Square (1024 × 1024)',
            self::LANDSCAPE => 'Landscape (1536 × 1024)',
            self::PORTRAIT => 'Portrait (1024 × 1536)',
        };
    }
}
