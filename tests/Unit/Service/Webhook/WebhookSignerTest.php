<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Webhook;

use App\Service\Webhook\WebhookSigner;
use App\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;

final class WebhookSignerTest extends UnitTestCase
{
    private WebhookSigner $signer;

    protected function setUp(): void
    {
        $this->signer = new WebhookSigner();
    }

    #[Test]
    public function signReturnsSha256PrefixedSignature(): void
    {
        $signature = $this->signer->sign('{"event":"test"}', 'my-secret');

        $this->assertStringStartsWith('sha256=', $signature);
        $this->assertSame('sha256=' . hash_hmac('sha256', '{"event":"test"}', 'my-secret'), $signature);
    }

    #[Test]
    public function verifyReturnsTrueForMatchingSignature(): void
    {
        $body = '{"event":"test"}';
        $secret = 'my-secret';
        $signature = $this->signer->sign($body, $secret);

        $this->assertTrue($this->signer->verify($body, $secret, $signature));
    }

    #[Test]
    public function verifyReturnsFalseForWrongSecret(): void
    {
        $body = '{"event":"test"}';
        $signature = $this->signer->sign($body, 'correct-secret');

        $this->assertFalse($this->signer->verify($body, 'wrong-secret', $signature));
    }

    #[Test]
    public function verifyReturnsFalseForTamperedBody(): void
    {
        $secret = 'my-secret';
        $signature = $this->signer->sign('{"event":"original"}', $secret);

        $this->assertFalse($this->signer->verify('{"event":"tampered"}', $secret, $signature));
    }
}
