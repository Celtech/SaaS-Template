<?php

declare(strict_types=1);

namespace App\Service\Webhook;

use RuntimeException;
use SodiumException;

/**
 * Encrypts webhook signing secrets at rest using libsodium authenticated symmetric
 * encryption (XSalsa20-Poly1305 via crypto_secretbox).
 *
 * Unlike client secrets or API keys, a webhook secret cannot be one-way hashed: the
 * server must recompute HMAC-SHA256(secret, body) on every delivery, which requires
 * the original plaintext. This is retrievable-at-rest by design, encrypted with a
 * dedicated key (WEBHOOK_SECRET_ENCRYPTION_KEY) separate from APP_SECRET.
 */
final class WebhookSecretCrypto
{
    private string $key;

    public function __construct(string $encryptionKey)
    {
        try {
            $key = sodium_hex2bin($encryptionKey);
        } catch (SodiumException) {
            $key = '';
        }

        if (\strlen($key) !== \SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('WEBHOOK_SECRET_ENCRYPTION_KEY must be a ' . (\SODIUM_CRYPTO_SECRETBOX_KEYBYTES * 2) . '-character hex string.');
        }

        $this->key = $key;
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return base64_encode($nonce . $ciphertext);
    }

    public function decrypt(string $stored): string
    {
        $raw = base64_decode($stored, strict: true);

        if ($raw === false || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Malformed webhook secret ciphertext.');
        }

        $nonce = substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);

        if ($plaintext === false) {
            throw new RuntimeException('Failed to decrypt webhook secret: ciphertext tampered or wrong key.');
        }

        return $plaintext;
    }
}
