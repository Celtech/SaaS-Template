<?php

declare(strict_types=1);

namespace App\Service\OAuth;

/** RFC 7636 — Proof Key for Code Exchange. Only the "S256" transform is supported. */
final class PkceVerifier
{
    public function challengeFromVerifier(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }

    public function verify(string $codeVerifier, string $expectedChallenge): bool
    {
        return hash_equals($expectedChallenge, $this->challengeFromVerifier($codeVerifier));
    }
}
