<?php

use App\Pricing\Money;

it('rounds nanodollars half up to four decimals for display', function (int $nanodollars, string $expected) {
    expect(Money::format($nanodollars))->toBe($expected);
})->with([
    'zero' => [0, '$0.0000'],
    'below the rounding threshold' => [49_999, '$0.0000'],
    'exactly on the rounding threshold' => [50_000, '$0.0001'],
    'a recorded charge with a half-unit remainder' => [404_750_000, '$0.4048'],
    'one dollar' => [1_000_000_000, '$1.0000'],
    'more than ten dollars' => [12_345_678_901, '$12.3457'],
]);

it('shows every nanodollar when formatting the exact amount', function (int $nanodollars, string $expected) {
    expect(Money::formatExact($nanodollars))->toBe($expected);
})->with([
    'a single nanodollar' => [1, '$0.000000001'],
    'a recorded charge' => [404_750_000, '$0.404750000'],
]);

it('parses the exact nine-decimal amounts Pixelforge reports', function (string $usd, int $expected) {
    expect(Money::parseUsd($usd))->toBe($expected);
})->with([
    'zero' => ['0.000000000', 0],
    'a single nanodollar' => ['0.000000001', 1],
    'a recorded total' => ['0.218942500', 218_942_500],
    'more than a dollar' => ['12.345678901', 12_345_678_901],
]);

it('rejects amounts that are not exactly nine decimals', function (string $usd) {
    Money::parseUsd($usd);
})->with([
    'fewer decimals' => ['0.2189425'],
    'more decimals' => ['0.2189425001'],
    'no decimals' => ['1'],
    'not a number' => ['abc'],
])->throws(UnexpectedValueException::class);
