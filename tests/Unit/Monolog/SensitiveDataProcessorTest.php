<?php

declare(strict_types=1);

namespace App\Tests\Unit\Monolog;

use App\Monolog\SensitiveDataProcessor;
use App\Tests\UnitTestCase;
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
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'test message',
            context: $context,
            extra: $extra,
        );
    }

    #[Test]
    public function it_passes_through_non_sensitive_context(): void
    {
        $record = $this->makeRecord(['user_id' => 42, 'action' => 'login']);
        $result = ($this->processor)($record);

        $this->assertSame(42, $result->context['user_id']);
        $this->assertSame('login', $result->context['action']);
    }

    #[Test]
    public function it_redacts_password_field(): void
    {
        $record = $this->makeRecord(['password' => 'super-secret']);
        $result = ($this->processor)($record);

        $this->assertSame('[REDACTED]', $result->context['password']);
    }

    #[Test]
    public function it_redacts_token_field(): void
    {
        $record = $this->makeRecord(['api_token' => 'tok_live_abc123']);
        $result = ($this->processor)($record);

        $this->assertSame('[REDACTED]', $result->context['api_token']);
    }

    #[Test]
    public function it_redacts_card_number_field(): void
    {
        $record = $this->makeRecord(['card_number' => '4111111111111111']);
        $result = ($this->processor)($record);

        $this->assertSame('[REDACTED]', $result->context['card_number']);
    }

    #[Test]
    public function it_redacts_cvv_field(): void
    {
        $record = $this->makeRecord(['cvv' => '123']);
        $result = ($this->processor)($record);

        $this->assertSame('[REDACTED]', $result->context['cvv']);
    }

    #[Test]
    public function it_redacts_authorization_header(): void
    {
        $record = $this->makeRecord(['authorization' => 'Bearer eyJhb...']);
        $result = ($this->processor)($record);

        $this->assertSame('[REDACTED]', $result->context['authorization']);
    }

    #[Test]
    public function it_redacts_keys_case_insensitively(): void
    {
        $record = $this->makeRecord(['PASSWORD' => 'secret', 'Api_Key' => 'key123']);
        $result = ($this->processor)($record);

        $this->assertSame('[REDACTED]', $result->context['PASSWORD']);
        $this->assertSame('[REDACTED]', $result->context['Api_Key']);
    }

    #[Test]
    public function it_redacts_nested_sensitive_keys(): void
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
    public function it_redacts_extra_array_too(): void
    {
        $record = $this->makeRecord([], ['secret' => 'shh']);
        $result = ($this->processor)($record);

        $this->assertSame('[REDACTED]', $result->extra['secret']);
    }

    #[Test]
    public function it_redacts_fields_containing_sensitive_substring(): void
    {
        $record = $this->makeRecord(['user_password_hash' => 'abc', 'stripe_api_key' => 'sk_live_xyz']);
        $result = ($this->processor)($record);

        $this->assertSame('[REDACTED]', $result->context['user_password_hash']);
        $this->assertSame('[REDACTED]', $result->context['stripe_api_key']);
    }

    #[Test]
    public function it_redacts_ssn_and_pan(): void
    {
        $record = $this->makeRecord(['ssn' => '123-45-6789', 'pan' => '4111111111111111']);
        $result = ($this->processor)($record);

        $this->assertSame('[REDACTED]', $result->context['ssn']);
        $this->assertSame('[REDACTED]', $result->context['pan']);
    }
}
