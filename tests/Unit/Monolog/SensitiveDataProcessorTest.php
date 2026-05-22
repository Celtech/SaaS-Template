<?php

declare(strict_types=1);

namespace App\Tests\Unit\Monolog;

use App\Monolog\SensitiveDataProcessor;
use App\Tests\UnitTestCase;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;

final class SensitiveDataProcessorTest extends UnitTestCase
{
    private SensitiveDataProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new SensitiveDataProcessor();
    }

    private function makeRecord(array $context = [], array $extra = []): LogRecord
    {
        return new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'test message',
            context: $context,
            extra: $extra,
        );
    }

    #[Test]
    public function itPassesThroughNonSensitiveContext(): void
    {
        $record = $this->makeRecord(['user_id' => 42, 'action' => 'login']);
        $result = ($this->processor)($record);

        $this->assertSame(42, $result->context['user_id']);
        $this->assertSame('login', $result->context['action']);
    }

    #[Test]
    public function itRedactsPasswordField(): void
    {
        $record = $this->makeRecord(['password' => 'super-secret']);
        $result = ($this->processor)($record);

        $this->assertSame('[REDACTED]', $result->context['password']);
    }

    #[Test]
    public function itRedactsTokenField(): void
    {
        $record = $this->makeRecord(['api_token' => 'tok_live_abc123']);
        $result = ($this->processor)($record);

        $this->assertSame('[REDACTED]', $result->context['api_token']);
    }

    #[Test]
    public function itRedactsCardNumberField(): void
    {
        $record = $this->makeRecord(['card_number' => '4111111111111111']);
        $result = ($this->processor)($record);

        $this->assertSame('[REDACTED]', $result->context['card_number']);
    }

    #[Test]
    public function itRedactsCvvField(): void
    {
        $record = $this->makeRecord(['cvv' => '123']);
        $result = ($this->processor)($record);

        $this->assertSame('[REDACTED]', $result->context['cvv']);
    }

    #[Test]
    public function itRedactsAuthorizationHeader(): void
    {
        $record = $this->makeRecord(['authorization' => 'Bearer eyJhb...']);
        $result = ($this->processor)($record);

        $this->assertSame('[REDACTED]', $result->context['authorization']);
    }

    #[Test]
    public function itRedactsKeysCaseInsensitively(): void
    {
        $record = $this->makeRecord(['PASSWORD' => 'secret', 'Api_Key' => 'key123']);
        $result = ($this->processor)($record);

        $this->assertSame('[REDACTED]', $result->context['PASSWORD']);
        $this->assertSame('[REDACTED]', $result->context['Api_Key']);
    }

    #[Test]
    public function itRedactsNestedSensitiveKeys(): void
    {
        $record = $this->makeRecord([
            'request' => [
                'body' => [
                    'password' => 'nested-secret',
                    'username' => 'alice',
                ],
            ],
        ]);
        $result = ($this->processor)($record);

        $this->assertSame('[REDACTED]', $result->context['request']['body']['password']);
        $this->assertSame('alice', $result->context['request']['body']['username']);
    }

    #[Test]
    public function itRedactsExtraArrayToo(): void
    {
        $record = $this->makeRecord([], ['secret' => 'shh']);
        $result = ($this->processor)($record);

        $this->assertSame('[REDACTED]', $result->extra['secret']);
    }

    #[Test]
    public function itRedactsFieldsContainingSensitiveSubstring(): void
    {
        $record = $this->makeRecord(['user_password_hash' => 'abc', 'stripe_api_key' => 'sk_live_xyz']);
        $result = ($this->processor)($record);

        $this->assertSame('[REDACTED]', $result->context['user_password_hash']);
        $this->assertSame('[REDACTED]', $result->context['stripe_api_key']);
    }

    #[Test]
    public function itRedactsSsnAndPan(): void
    {
        $record = $this->makeRecord(['ssn' => '123-45-6789', 'pan' => '4111111111111111']);
        $result = ($this->processor)($record);

        $this->assertSame('[REDACTED]', $result->context['ssn']);
        $this->assertSame('[REDACTED]', $result->context['pan']);
    }
}
