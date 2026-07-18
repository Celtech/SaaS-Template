<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\OAuth;

use App\Service\OAuth\TokenGenerator;
use App\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;

final class TokenGeneratorTest extends UnitTestCase
{
    private TokenGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new TokenGenerator();
    }

    #[Test]
    public function generateTokenReturns80HexChars(): void
    {
        $token = $this->generator->generateToken();

        $this->assertSame(80, \strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{80}$/', $token);
    }

    #[Test]
    public function generateTokenIsUnique(): void
    {
        $this->assertNotSame(
            $this->generator->generateToken(),
            $this->generator->generateToken(),
        );
    }

    #[Test]
    public function generateClientIdReturns32HexChars(): void
    {
        $id = $this->generator->generateClientId();

        $this->assertSame(32, \strlen($id));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
    }

    #[Test]
    public function generateClientSecretReturns64HexChars(): void
    {
        $secret = $this->generator->generateClientSecret();

        $this->assertSame(64, \strlen($secret));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
    }

    #[Test]
    public function hashTokenReturnsSha256Hex(): void
    {
        $hash = $this->generator->hashToken('test');

        $this->assertSame(hash('sha256', 'test'), $hash);
        $this->assertSame(64, \strlen($hash));
    }

    #[Test]
    public function verifySecretReturnsTrueForMatchingInput(): void
    {
        $plain = 'my-secret';
        $hash = $this->generator->hashToken($plain);

        $this->assertTrue($this->generator->verifySecret($plain, $hash));
    }

    #[Test]
    public function verifySecretReturnsFalseForWrongInput(): void
    {
        $hash = $this->generator->hashToken('correct');

        $this->assertFalse($this->generator->verifySecret('wrong', $hash));
    }

    #[Test]
    public function generateUserCodeReturnsExpectedFormat(): void
    {
        $code = $this->generator->generateUserCode();

        $this->assertMatchesRegularExpression('/^[BCDFGHJKLMNPQRSTVWXZ23456789]{4}-[BCDFGHJKLMNPQRSTVWXZ23456789]{4}$/', $code);
    }

    #[Test]
    public function generateUserCodeExcludesAmbiguousCharacters(): void
    {
        for ($i = 0; $i < 50; ++$i) {
            $code = $this->generator->generateUserCode();
            $this->assertDoesNotMatchRegularExpression('/[01OI]/', $code);
        }
    }

    #[Test]
    public function generateUserCodeIsUnique(): void
    {
        $this->assertNotSame(
            $this->generator->generateUserCode(),
            $this->generator->generateUserCode(),
        );
    }
}
