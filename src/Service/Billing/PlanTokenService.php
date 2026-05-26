<?php

declare(strict_types=1);

namespace App\Service\Billing;

use JsonException;

/**
 * Generates and validates HMAC-signed plan passthrough tokens.
 *
 * Tokens allow external sites (e.g. the marketing pricing page) to pre-select a plan
 * for a new registration without exposing plan IDs in query strings or allowing
 * tampering with the selected plan or billing interval.
 *
 * Token format:  <base64url(json_payload)>.<hmac_sha256_hex>
 * Payload:       {"plan":"basic","interval":"month","exp":<unix_timestamp>}
 *
 * See docs/PLAN_TOKEN.md for integration documentation.
 */
final class PlanTokenService
{
    private const int TTL_SECONDS = 3600;

    public function __construct(private readonly string $secret)
    {
    }

    public function generate(string $planSlug, string $interval): string
    {
        $payload = $this->base64UrlEncode((string) json_encode([
            'plan' => $planSlug,
            'interval' => $interval,
            'exp' => time() + self::TTL_SECONDS,
        ], \JSON_THROW_ON_ERROR));

        $signature = hash_hmac('sha256', $payload, $this->secret);

        return $payload . '.' . $signature;
    }

    /**
     * Validates the token and returns the decoded payload on success, or null on failure.
     *
     * @return array{plan: string, interval: string}|null
     */
    public function validate(string $token): ?array
    {
        $parts = explode('.', $token, 2);
        if (\count($parts) !== 2) {
            return null;
        }

        [$encodedPayload, $signature] = $parts;

        if (!hash_equals(hash_hmac('sha256', $encodedPayload, $this->secret), $signature)) {
            return null;
        }

        try {
            $data = json_decode($this->base64UrlDecode($encodedPayload), true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!\is_array($data)) {
            return null;
        }

        if (!isset($data['plan'], $data['interval'], $data['exp'])) {
            return null;
        }

        if (!\is_string($data['plan']) || !\is_string($data['interval']) || !\is_int($data['exp'])) {
            return null;
        }

        if ($data['exp'] < time()) {
            return null;
        }

        if (!\in_array($data['interval'], ['month', 'year'], true)) {
            return null;
        }

        return ['plan' => $data['plan'], 'interval' => $data['interval']];
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
