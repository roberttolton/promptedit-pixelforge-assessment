<?php

namespace App\Enums;

enum ImageQuality: string
{
    case LOW = 'low';

    case MEDIUM = 'medium';

    case HIGH = 'high';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
