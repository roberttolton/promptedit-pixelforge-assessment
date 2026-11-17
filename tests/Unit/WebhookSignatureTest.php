<?php

use App\Integrations\Pixelforge\WebhookSignature;

function sign(string $body, string $secret, int $timestamp): string
{
    return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
}

it('accepts a correctly signed body', function () {
    expect(WebhookSignature::isValid('{"a":1}', sign('{"a":1}', 'whsec_x', 1000), 'whsec_x', 1000))->toBeTrue();
});

it('rejects bad signatures, tampered bodies, wrong secrets and malformed headers', function (string $body, ?string $header, string $secret) {
    expect(WebhookSignature::isValid($body, $header, $secret, 1000))->toBeFalse();
})->with([
    'tampered body' => ['{"a":2}', sign('{"a":1}', 'whsec_x', 1000), 'whsec_x'],
    'wrong secret' => ['{"a":1}', sign('{"a":1}', 'whsec_other', 1000), 'whsec_x'],
    'missing header' => ['{"a":1}', null, 'whsec_x'],
    'garbage header' => ['{"a":1}', 'nonsense', 'whsec_x'],
    'empty secret is never valid' => ['{"a":1}', sign('{"a":1}', '', 1000), ''],
]);

it('rejects timestamps more than 300 seconds from now', function () {
    $header = sign('{}', 'whsec_x', 1000);

    expect(WebhookSignature::isValid('{}', $header, 'whsec_x', 1300))->toBeTrue()
        ->and(WebhookSignature::isValid('{}', $header, 'whsec_x', 1301))->toBeFalse()
        ->and(WebhookSignature::isValid('{}', $header, 'whsec_x', 699))->toBeFalse();
});
