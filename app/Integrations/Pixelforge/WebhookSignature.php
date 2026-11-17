<?php

namespace App\Integrations\Pixelforge;

/**
 * Verifies `Pixelforge-Signature: t=<unix>,v1=<hex>`, an HMAC-SHA256 of "<t>.<raw body>".
 */
final class WebhookSignature
{
    public const int TOLERANCE_SECONDS = 300;

    public static function isValid(string $rawBody, ?string $header, string $secret, ?int $now = null): bool
    {
        if ($secret === '' || $header === null) {
            return false;
        }

        $parts = [];

        foreach (explode(',', $header) as $part) {

            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $parts[trim($key)] = trim($value);

        }

        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';

        if (! ctype_digit($timestamp) || abs(($now ?? time()) - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret), $signature);
    }
}
