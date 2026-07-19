<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Entity\OAuthAccessToken;
use App\Entity\OAuthClient;
use App\Entity\OAuthRefreshToken;
use App\Entity\Organization;
use App\Entity\User;
use App\Repository\OAuthAccessTokenRepository;
use App\Repository\OAuthRefreshTokenRepository;
use App\Service\Audit\AuditLogger;
use DateTimeImmutable;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class TokenService
{
    private const ACCESS_TOKEN_TTL = 3600;       // 1 hour
    private const REFRESH_TOKEN_TTL = 2592000;    // 30 days
    private const CACHE_PREFIX = 'oauth_at_';

    public function __construct(
        private readonly OAuthAccessTokenRepository $accessTokens,
        private readonly OAuthRefreshTokenRepository $refreshTokens,
        private readonly TokenGenerator $generator,
        private readonly AuditLogger $auditLogger,
        #[Autowire(service: 'cache.oauth_tokens')]
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * Issues a new access token and, unless suppressed, an accompanying refresh token.
     *
     * @param  string[]  $scopes
     *
     * @return array{OAuthAccessToken, OAuthRefreshToken|null, string, string|null}
     *         [accessToken, refreshToken, plainAccessToken, plainRefreshToken]
     */
    public function issueTokenPair(
        OAuthClient $client,
        ?User $user,
        ?Organization $organization,
        array $scopes,
        bool $includeRefreshToken = true,
    ): array {
        $plainAccess = $this->generator->generateToken();
        $now = new DateTimeImmutable();

        $accessToken = new OAuthAccessToken(
            tokenHash: $this->generator->hashToken($plainAccess),
            client: $client,
            user: $user,
            organization: $organization,
            scopes: $scopes,
            expiresAt: $now->modify('+' . self::ACCESS_TOKEN_TTL . ' seconds'),
        );

        $this->accessTokens->save($accessToken, flush: !$includeRefreshToken);
        $this->cacheAccessToken($plainAccess, $accessToken);

        if (!$includeRefreshToken) {
            $this->logIssued($client, $user, $scopes);

            return [$accessToken, null, $plainAccess, null];
        }

        $plainRefresh = $this->generator->generateToken();

        $refreshToken = new OAuthRefreshToken(
            tokenHash: $this->generator->hashToken($plainRefresh),
            client: $client,
            user: $user,
            organization: $organization,
            scopes: $scopes,
            expiresAt: $now->modify('+' . self::REFRESH_TOKEN_TTL . ' seconds'),
        );

        $this->refreshTokens->save($refreshToken, flush: true);
        $this->logIssued($client, $user, $scopes);

        return [$accessToken, $refreshToken, $plainAccess, $plainRefresh];
    }

    public function validateAccessToken(string $plainToken): ?OAuthAccessToken
    {
        $hash = $this->generator->hashToken($plainToken);
        $cacheKey = self::CACHE_PREFIX . $hash;

        $item = $this->cache->getItem($cacheKey);
        if ($item->isHit()) {
            /** @var array<string, mixed> $data */
            $data = $item->get();
            if (($data['revoked'] ?? false) === true) {
                return null;
            }
        }

        $token = $this->accessTokens->findByTokenHash($hash);

        if ($token === null || !$token->isActive()) {
            // Populate cache with revoked/expired marker to avoid repeat DB hits.
            if ($token !== null) {
                $this->markCacheRevoked($hash);
            }

            return null;
        }

        if (!$item->isHit()) {
            $this->cacheAccessToken($plainToken, $token);
        }

        return $token;
    }

    /**
     * Validates and rotates a refresh token: revokes the old one, issues a new pair.
     *
     * The requesting client must be the same client the refresh token was issued to
     * (RFC 6749 §6) — callers must authenticate the client before calling this.
     *
     * @return array{OAuthAccessToken, OAuthRefreshToken, string, string}|null
     */
    public function rotateRefreshToken(string $plainRefreshToken, OAuthClient $requestingClient): ?array
    {
        $hash = $this->generator->hashToken($plainRefreshToken);
        $refreshToken = $this->refreshTokens->findByTokenHash($hash);

        if ($refreshToken === null || !$refreshToken->isActive()) {
            return null;
        }

        if (!$refreshToken->getClient()->getId()->equals($requestingClient->getId())) {
            return null;
        }

        $refreshToken->revoke();
        $this->refreshTokens->save($refreshToken, flush: true);
        $this->logRevoked($refreshToken->getClient(), $refreshToken->getUser(), 'refresh_token');

        [$newAccessToken, $newRefreshToken, $plainAccess, $plainRefresh] = $this->issueTokenPair(
            $refreshToken->getClient(),
            $refreshToken->getUser(),
            $refreshToken->getOrganization(),
            $refreshToken->getScopes(),
        );

        \assert($newRefreshToken !== null && $plainRefresh !== null);

        return [$newAccessToken, $newRefreshToken, $plainAccess, $plainRefresh];
    }

    public function revokeAccessToken(string $plainToken): void
    {
        $hash = $this->generator->hashToken($plainToken);
        $token = $this->accessTokens->findByTokenHash($hash);

        if ($token !== null && !$token->isRevoked()) {
            $token->revoke();
            $this->accessTokens->save($token, flush: true);
            $this->logRevoked($token->getClient(), $token->getUser(), 'access_token');
        }

        $this->markCacheRevoked($hash);
    }

    public function revokeRefreshToken(string $plainToken): void
    {
        $hash = $this->generator->hashToken($plainToken);
        $token = $this->refreshTokens->findByTokenHash($hash);

        if ($token !== null && !$token->isRevoked()) {
            $token->revoke();
            $this->refreshTokens->save($token, flush: true);
            $this->logRevoked($token->getClient(), $token->getUser(), 'refresh_token');
        }
    }

    public static function accessTokenTtl(): int
    {
        return self::ACCESS_TOKEN_TTL;
    }

    private function cacheAccessToken(string $plainToken, OAuthAccessToken $token): void
    {
        $hash = $this->generator->hashToken($plainToken);
        $ttl = $token->getExpiresAt()->getTimestamp() - time();

        if ($ttl <= 0) {
            return;
        }

        $item = $this->cache->getItem(self::CACHE_PREFIX . $hash);
        $item->set(['revoked' => false]);
        $item->expiresAfter($ttl);
        $this->cache->save($item);
    }

    private function markCacheRevoked(string $hash): void
    {
        $item = $this->cache->getItem(self::CACHE_PREFIX . $hash);
        $item->set(['revoked' => true]);
        $item->expiresAfter(300); // short TTL — no point keeping revoked markers long
        $this->cache->save($item);
    }

    /** @param string[] $scopes */
    private function logIssued(OAuthClient $client, ?User $user, array $scopes): void
    {
        $this->auditLogger->logOAuthEvent(
            'token.issued',
            $client->getId()->toRfc4122(),
            'oauth_client',
            newValue: ['scopes' => $scopes],
            actorId: $user?->getId()->toRfc4122() ?? $client->getId()->toRfc4122(),
            actorType: $user !== null ? 'user' : 'oauth_client',
        );
    }

    private function logRevoked(OAuthClient $client, ?User $user, string $tokenType): void
    {
        $this->auditLogger->logOAuthEvent(
            'token.revoked',
            $client->getId()->toRfc4122(),
            'oauth_client',
            newValue: ['token_type' => $tokenType],
            actorId: $user?->getId()->toRfc4122() ?? $client->getId()->toRfc4122(),
            actorType: $user !== null ? 'user' : 'oauth_client',
        );
    }
}
