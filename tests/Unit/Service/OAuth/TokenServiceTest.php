<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\OAuth;

use App\Entity\OAuthAccessToken;
use App\Entity\OAuthClient;
use App\Entity\OAuthRefreshToken;
use App\Entity\User;
use App\Repository\OAuthAccessTokenRepository;
use App\Repository\OAuthRefreshTokenRepository;
use App\Service\Audit\AuditLogger;
use App\Service\OAuth\TokenGenerator;
use App\Service\OAuth\TokenService;
use App\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Uid\Uuid;

/**
 * Token issuance and revocation are OAuth's equivalent of a login/logout event
 * and must be audited the same way — this test guards that TokenService never
 * silently drops the AuditLogger call for any of its code paths.
 */
final class TokenServiceTest extends UnitTestCase
{
    private OAuthAccessTokenRepository&MockObject $accessTokens;
    private OAuthRefreshTokenRepository&MockObject $refreshTokens;
    private AuditLogger&MockObject $auditLogger;
    private TokenService $service;

    protected function setUp(): void
    {
        $this->accessTokens = $this->createMock(OAuthAccessTokenRepository::class);
        $this->refreshTokens = $this->createMock(OAuthRefreshTokenRepository::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);

        $this->service = new TokenService(
            $this->accessTokens,
            $this->refreshTokens,
            new TokenGenerator(),
            $this->auditLogger,
            new ArrayAdapter(),
        );
    }

    #[Test]
    public function issuingATokenPairLogsAnOAuthEvent(): void
    {
        $client = $this->createMock(OAuthClient::class);
        $client->method('getId')->willReturn(Uuid::v7());

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(Uuid::v7());

        $this->auditLogger->expects($this->once())
            ->method('logOAuthEvent')
            ->with(
                'token.issued',
                $this->anything(),
                'oauth_client',
                oldValue: null,
                newValue: ['scopes' => ['api:read']],
                actorId: $this->anything(),
                actorType: 'user',
            );

        $this->service->issueTokenPair($client, $user, null, ['api:read']);
    }

    #[Test]
    public function issuingAM2mTokenPairLogsTheClientAsTheActor(): void
    {
        $client = $this->createMock(OAuthClient::class);
        $client->method('getId')->willReturn(Uuid::v7());

        $this->auditLogger->expects($this->once())
            ->method('logOAuthEvent')
            ->with(
                'token.issued',
                $this->anything(),
                'oauth_client',
                oldValue: null,
                newValue: ['scopes' => ['api:read']],
                actorId: $this->anything(),
                actorType: 'oauth_client',
            );

        $this->service->issueTokenPair($client, null, null, ['api:read'], includeRefreshToken: false);
    }

    #[Test]
    public function revokingAnAccessTokenLogsAnOAuthEvent(): void
    {
        $client = $this->createMock(OAuthClient::class);
        $client->method('getId')->willReturn(Uuid::v7());

        $token = $this->createMock(OAuthAccessToken::class);
        $token->method('isRevoked')->willReturn(false);
        $token->method('getClient')->willReturn($client);
        $token->method('getUser')->willReturn(null);

        $this->accessTokens->method('findByTokenHash')->willReturn($token);

        $this->auditLogger->expects($this->once())
            ->method('logOAuthEvent')
            ->with(
                'token.revoked',
                $this->anything(),
                'oauth_client',
                oldValue: null,
                newValue: ['token_type' => 'access_token'],
                actorId: $this->anything(),
                actorType: 'oauth_client',
            );

        $this->service->revokeAccessToken('plain-token');
    }

    #[Test]
    public function revokingAnAlreadyRevokedTokenDoesNotLogAgain(): void
    {
        $token = $this->createMock(OAuthAccessToken::class);
        $token->method('isRevoked')->willReturn(true);

        $this->accessTokens->method('findByTokenHash')->willReturn($token);

        $this->auditLogger->expects($this->never())->method('logOAuthEvent');

        $this->service->revokeAccessToken('plain-token');
    }

    #[Test]
    public function revokingARefreshTokenLogsAnOAuthEvent(): void
    {
        $client = $this->createMock(OAuthClient::class);
        $client->method('getId')->willReturn(Uuid::v7());

        $token = $this->createMock(OAuthRefreshToken::class);
        $token->method('isRevoked')->willReturn(false);
        $token->method('getClient')->willReturn($client);
        $token->method('getUser')->willReturn(null);

        $this->refreshTokens->method('findByTokenHash')->willReturn($token);

        $this->auditLogger->expects($this->once())
            ->method('logOAuthEvent')
            ->with(
                'token.revoked',
                $this->anything(),
                'oauth_client',
                oldValue: null,
                newValue: ['token_type' => 'refresh_token'],
                actorId: $this->anything(),
                actorType: 'oauth_client',
            );

        $this->service->revokeRefreshToken('plain-token');
    }

    #[Test]
    public function rotatingARefreshTokenLogsBothTheRevocationAndTheNewIssuance(): void
    {
        $client = $this->createMock(OAuthClient::class);
        $client->method('getId')->willReturn(Uuid::v7());

        $oldRefreshToken = $this->createMock(OAuthRefreshToken::class);
        $oldRefreshToken->method('isActive')->willReturn(true);
        $oldRefreshToken->method('getClient')->willReturn($client);
        $oldRefreshToken->method('getUser')->willReturn(null);
        $oldRefreshToken->method('getOrganization')->willReturn(null);
        $oldRefreshToken->method('getScopes')->willReturn(['api:read']);

        $this->refreshTokens->method('findByTokenHash')->willReturn($oldRefreshToken);

        $loggedActions = [];
        $this->auditLogger->expects($this->exactly(2))
            ->method('logOAuthEvent')
            ->willReturnCallback(static function (string $action) use (&$loggedActions): void {
                $loggedActions[] = $action;
            });

        $result = $this->service->rotateRefreshToken('plain-refresh-token', $client);

        $this->assertNotNull($result);
        $this->assertSame(['token.revoked', 'token.issued'], $loggedActions);
    }
}
