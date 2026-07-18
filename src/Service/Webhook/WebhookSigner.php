<?php

declare(strict_types=1);

namespace App\Service\Webhook;

/** Computes the X-Webhook-Signature header: sha256=HMAC-SHA256(secret, body). */
final class WebhookSigner
{
    public function sign(string $body, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    public function verify(string $body, string $secret, string $signatureHeader): bool
    {
        return hash_equals($this->sign($body, $secret), $signatureHeader);
    }
}
