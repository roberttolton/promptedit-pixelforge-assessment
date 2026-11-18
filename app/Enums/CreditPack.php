<?php

namespace App\Enums;

enum CreditPack: string
{
    case FIVE_DOLLARS = 'five-dollars';

    case TEN_DOLLARS = 'ten-dollars';

    case TWENTY_FIVE_DOLLARS = 'twenty-five-dollars';

    /**
     * Nanodollars of credit the pack adds.
     */
    public function nanodollars(): int
    {
        return match ($this) {
            self::FIVE_DOLLARS => 5_000_000_000,
            self::TEN_DOLLARS => 10_000_000_000,
            self::TWENTY_FIVE_DOLLARS => 25_000_000_000,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::FIVE_DOLLARS => '$5',
            self::TEN_DOLLARS => '$10',
            self::TWENTY_FIVE_DOLLARS => '$25',
        };
    }
}
