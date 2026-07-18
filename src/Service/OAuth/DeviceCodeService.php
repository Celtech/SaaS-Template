<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Entity\OAuthClient;
use App\Entity\OAuthDeviceCode;
use App\Entity\Organization;
use App\Entity\User;
use App\Repository\OAuthDeviceCodeRepository;
use DateTimeImmutable;

class DeviceCodeService
{
    private const DEVICE_CODE_TTL_SECONDS = 600; // 10 minutes (RFC 8628 example)
    private const POLL_INTERVAL_SECONDS = 5;

    public function __construct(
        private readonly OAuthDeviceCodeRepository $deviceCodes,
        private readonly TokenGenerator $generator,
    ) {
    }

    /**
     * @param string[] $scopes
     *
     * @return array{OAuthDeviceCode, string, string} entity, plain device_code, plain user_code
     */
    public function issue(OAuthClient $client, array $scopes): array
    {
        $plainDeviceCode = $this->generator->generateToken();
        $plainUserCode = $this->generator->generateUserCode();

        $deviceCode = new OAuthDeviceCode(
            deviceCodeHash: $this->generator->hashToken($plainDeviceCode),
            userCodeHash: $this->hashUserCode($plainUserCode),
            client: $client,
            scopes: $scopes,
            interval: self::POLL_INTERVAL_SECONDS,
            expiresAt: new DateTimeImmutable()->modify('+' . self::DEVICE_CODE_TTL_SECONDS . ' seconds'),
        );

        $this->deviceCodes->save($deviceCode, flush: true);

        return [$deviceCode, $plainDeviceCode, $plainUserCode];
    }

    /** Looks up by user_code. Input is normalized (upper-cased, whitespace trimmed) before hashing. */
    public function findByUserCode(string $userCode): ?OAuthDeviceCode
    {
        return $this->deviceCodes->findByUserCodeHash($this->hashUserCode($userCode));
    }

    public function findByDeviceCode(string $plainDeviceCode): ?OAuthDeviceCode
    {
        return $this->deviceCodes->findByDeviceCodeHash($this->generator->hashToken($plainDeviceCode));
    }

    public function approve(OAuthDeviceCode $deviceCode, User $user, ?Organization $organization): void
    {
        $deviceCode->markApproved($user, $organization);
        $this->deviceCodes->save($deviceCode, flush: true);
    }

    public function deny(OAuthDeviceCode $deviceCode): void
    {
        $deviceCode->markDenied();
        $this->deviceCodes->save($deviceCode, flush: true);
    }

    public function persist(OAuthDeviceCode $deviceCode): void
    {
        $this->deviceCodes->save($deviceCode, flush: true);
    }

    public static function deviceCodeTtl(): int
    {
        return self::DEVICE_CODE_TTL_SECONDS;
    }

    private function hashUserCode(string $userCode): string
    {
        $normalized = strtoupper(trim($userCode));

        return $this->generator->hashToken($normalized);
    }
}
