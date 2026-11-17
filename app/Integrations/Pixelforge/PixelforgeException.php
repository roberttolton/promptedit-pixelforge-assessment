<?php

namespace App\Integrations\Pixelforge;

use Illuminate\Http\Client\Response;
use RuntimeException;

class PixelforgeException extends RuntimeException
{
    private const array RETRYABLE_ERROR_CODES = [
        'rate_limit_exceeded',
        'concurrency_limit_exceeded',
        'overloaded',
    ];

    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(Response $response): self
    {
        $retryAfter = $response->header('Retry-After');

        return new self(
            $response->status(),
            (string) $response->json('error.code', 'unknown_error'),
            (string) $response->json('error.message', 'Unexpected response from Pixelforge.'),
            $retryAfter === '' ? null : (int) $retryAfter,
        );
    }

    public function isRetryable(): bool
    {
        return in_array($this->errorCode, self::RETRYABLE_ERROR_CODES, true) || $this->status >= 500;
    }
}
