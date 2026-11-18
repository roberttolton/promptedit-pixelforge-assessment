<?php

namespace App\Pricing;

use UnexpectedValueException;

/**
 * Display formatting for integer nanodollars. Recorded amounts stay exact; only the
 * rendered string is rounded, half up, using integer maths.
 */
final class Money
{
    public const int NANODOLLAR_DECIMALS = 9;

    /**
     * The default of 4 decimals is hundredths of a cent, enough to tell a $0.0004
     * generation from a $0.0040 one.
     */
    public static function format(int $nanodollars, int $decimals = 4): string
    {
        if ($nanodollars < 0) {
            return '-'.self::format(-$nanodollars, $decimals);
        }

        $nanodollarsPerUnit = 10 ** (self::NANODOLLAR_DECIMALS - $decimals);
        $units = intdiv($nanodollars + intdiv($nanodollarsPerUnit, 2), $nanodollarsPerUnit);
        $unitsPerDollar = 10 ** $decimals;

        return '$'.intdiv($units, $unitsPerDollar).'.'.str_pad((string) ($units % $unitsPerDollar), $decimals, '0', STR_PAD_LEFT);
    }

    public static function formatExact(int $nanodollars): string
    {
        return self::format($nanodollars, self::NANODOLLAR_DECIMALS);
    }

    /**
     * Parses an exact decimal string with nine decimals, as Pixelforge reports cost_usd.
     */
    public static function parseUsd(string $usd): int
    {
        if (preg_match('/^(\d+)\.(\d{9})$/', $usd, $matches) !== 1) {
            throw new UnexpectedValueException("Expected a USD amount with nine decimals, got [{$usd}].");
        }

        return (int) $matches[1] * 10 ** self::NANODOLLAR_DECIMALS + (int) $matches[2];
    }
}
