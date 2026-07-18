<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\OAuth;

use App\Service\OAuth\PkceVerifier;
use App\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;

final class PkceVerifierTest extends UnitTestCase
{
    private PkceVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new PkceVerifier();
    }

    #[Test]
    public function challengeFromVerifierMatchesRfc7636AppendixBVector(): void
    {
        // https://www.rfc-editor.org/rfc/rfc7636#appendix-B
        $codeVerifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $expectedChallenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

        $this->assertSame($expectedChallenge, $this->verifier->challengeFromVerifier($codeVerifier));
    }

    #[Test]
    public function verifyReturnsTrueForMatchingVerifier(): void
    {
        $codeVerifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $challenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

        $this->assertTrue($this->verifier->verify($codeVerifier, $challenge));
    }

    #[Test]
    public function verifyReturnsFalseForWrongVerifier(): void
    {
        $challenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

        $this->assertFalse($this->verifier->verify('some-other-verifier', $challenge));
    }

    #[Test]
    public function challengeContainsNoBase64PaddingOrUnsafeCharacters(): void
    {
        $challenge = $this->verifier->challengeFromVerifier('arbitrary-verifier-value');

        $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $challenge);
    }
}
