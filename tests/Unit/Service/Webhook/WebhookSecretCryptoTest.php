<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Webhook;

use App\Service\Webhook\WebhookSecretCrypto;
use App\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class WebhookSecretCryptoTest extends UnitTestCase
{
    private WebhookSecretCrypto $crypto;

    protected function setUp(): void
    {
        $this->crypto = new WebhookSecretCrypto(str_repeat('ab', 32));
    }

    #[Test]
    public function decryptReturnsTheOriginalPlaintext(): void
    {
        $secret = 'whsec_test_1234567890abcdef';

        $ciphertext = $this->crypto->encrypt($secret);

        $this->assertNotSame($secret, $ciphertext);
        $this->assertSame($secret, $this->crypto->decrypt($ciphertext));
    }

    #[Test]
    public function encryptProducesDifferentCiphertextEachTime(): void
    {
        $secret = 'whsec_test_1234567890abcdef';

        $this->assertNotSame($this->crypto->encrypt($secret), $this->crypto->encrypt($secret));
    }

    #[Test]
    public function decryptRejectsTamperedCiphertext(): void
    {
        $ciphertext = $this->crypto->encrypt('whsec_test_1234567890abcdef');
        $tampered = substr($ciphertext, 0, -4) . 'xxxx';

        $this->expectException(RuntimeException::class);
        $this->crypto->decrypt($tampered);
    }

    #[Test]
    public function constructorRejectsWrongLengthKey(): void
    {
        $this->expectException(RuntimeException::class);
        new WebhookSecretCrypto('too-short');
    }
}
