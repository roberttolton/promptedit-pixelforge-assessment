<?php

namespace App\Pricing;

use InvalidArgumentException;

/**
 * Per-token rates in nanodollars. $5.00 per 1M tokens is 5000 nanodollars per token.
 */
final readonly class Rates
{
    public function __construct(
        public int $inputText,
        public int $inputImage,
        public int $output,
        public int $reasoning,
    ) {}

    /**
     * @param  array{input_text: int, input_image: int, output: int, reasoning: int}  $nanoPerToken
     */
    public static function fromArray(array $nanoPerToken): self
    {
        return new self(
            inputText: $nanoPerToken['input_text'],
            inputImage: $nanoPerToken['input_image'],
            output: $nanoPerToken['output'],
            reasoning: $nanoPerToken['reasoning'],
        );
    }

    /**
     * Convert a "USD per 1M tokens" decimal string (e.g. "12.50") without touching floats.
     */
    public static function nanoPerToken(string $dollarsPerMillion): int
    {
        if (! is_numeric($dollarsPerMillion)) {
            throw new InvalidArgumentException("Rate [{$dollarsPerMillion}] is not a numeric dollar amount.");
        }

        return (int) bcmul($dollarsPerMillion, '1000', 0);
    }

    /**
     * @return array{input_text: int, input_image: int, output: int, reasoning: int}
     */
    public function toArray(): array
    {
        return [
            'input_text' => $this->inputText,
            'input_image' => $this->inputImage,
            'output' => $this->output,
            'reasoning' => $this->reasoning,
        ];
    }
}
