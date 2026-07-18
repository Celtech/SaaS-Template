<?php

declare(strict_types=1);

namespace App\Service\OAuth;

final class TokenGenerator
{
    /** Excludes visually ambiguous characters (0/O, 1/I) per RFC 8628 §6.1. */
    private const USER_CODE_ALPHABET = 'BCDFGHJKLMNPQRSTVWXZ23456789';

    /** 40 random bytes → 80 hex chars. */
    public function generateToken(): string
    {
        return bin2hex(random_bytes(40));
    }

    /** 8-character user-facing code (RFC 8628 §3.2), formatted as "XXXX-XXXX". */
    public function generateUserCode(): string
    {
        $alphabetLength = \strlen(self::USER_CODE_ALPHABET);
        $chars = '';

        for ($i = 0; $i < 8; ++$i) {
            $chars .= self::USER_CODE_ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        return substr($chars, 0, 4) . '-' . substr($chars, 4, 4);
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
