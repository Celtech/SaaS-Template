<?php

declare(strict_types=1);

namespace App\Monolog;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;

/**
 * Scrubs sensitive field names from all log context and extra data.
 * This is a safety net — code should never pass sensitive values to loggers.
 * Required for SOC2 confidentiality and PCI DSS compliance.
 */
#[AsMonologProcessor]
final class SensitiveDataProcessor
{
    private const SCRUBBED_KEYS = [
        'password',
        'passwd',
        'pass',
        'secret',
        'client_secret',
        'token',
        'api_token',
        'api_key',
        'apikey',
        'authorization',
        'x-api-key',
        'x_api_key',
        'card',
        'card_number',
        'cardnumber',
        'cvv',
        'cvc',
        'ssn',
        'social_security',
        'credit_card',
        'pan',
        'expiry',
        'exp_month',
        'exp_year',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->scrub($record->context),
            extra: $this->scrub($record->extra),
        );
    }

    /**
     * @param array<mixed, mixed> $data
     *
     * @return array<mixed, mixed>
     */
    private function scrub(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                $data[$key] = '[REDACTED]';
            } elseif (\is_array($value)) {
                $data[$key] = $this->scrub($value);
            }
        }

        return $data;
    }

    private function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);

        foreach (self::SCRUBBED_KEYS as $sensitiveKey) {
            if (str_contains($lower, $sensitiveKey)) {
                return true;
            }
        }

        return false;
    }
}
