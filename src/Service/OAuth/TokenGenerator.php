<?php

declare(strict_types=1);

namespace App\Service\OAuth;

final class TokenGenerator
{
    /** 40 random bytes → 80 hex chars. */
    public function generateToken(): string
    {
        return bin2hex(random_bytes(40));
    }

    /** 16 random bytes → 32 hex chars for the client_id. */
    public function generateClientId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** 32 random bytes → 64 hex chars for the client_secret. */
    public function generateClientSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function hashToken(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public function verifySecret(string $plaintext, string $storedHash): bool
    {
        return hash_equals($storedHash, hash('sha256', $plaintext));
    }
}
