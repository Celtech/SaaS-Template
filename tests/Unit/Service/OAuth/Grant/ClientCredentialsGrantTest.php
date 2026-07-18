<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\OAuth\Grant;

use App\Entity\OAuthAccessToken;
use App\Entity\OAuthClient;
use App\Entity\OAuthRefreshToken;
use App\Service\OAuth\ClientCredentialsExtractor;
use App\Service\OAuth\ClientService;
use App\Service\OAuth\Grant\ClientCredentialsGrant;
use App\Service\OAuth\TokenService;
use App\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\Request;

final class ClientCredentialsGrantTest extends UnitTestCase
{
    private ClientService&MockObject $clientService;
    private TokenService&MockObject $tokenService;
    private ClientCredentialsGrant $grant;

    protected function setUp(): void
    {
        $this->clientService = $this->createMock(ClientService::class);
        $this->tokenService = $this->createMock(TokenService::class);
        $this->grant = new ClientCredentialsGrant($this->clientService, $this->tokenService, new ClientCredentialsExtractor());
    }

    #[Test]
    public function returnsInvalidClientWhenCredentialsMissing(): void
    {
        $request = Request::create('/oauth/token', 'POST', ['grant_type' => 'client_credentials']);

        $response = $this->grant->handle($request);

        $this->assertSame(401, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('invalid_client', $data['error']);
    }

    #[Test]
    public function returnsInvalidClientWhenClientNotFound(): void
    {
        $this->clientService->method('validateClientCredentials')->willReturn(null);

        $request = Request::create('/oauth/token', 'POST', [
            'grant_type' => 'client_credentials',
            'client_id' => 'bad_id',
            'client_secret' => 'bad_secret',
        ]);

        $response = $this->grant->handle($request);

        $this->assertSame(401, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('invalid_client', $data['error']);
    }

    #[Test]
    public function returnsUnauthorizedClientWhenGrantNotAllowed(): void
    {
        $client = $this->createMock(OAuthClient::class);
        $client->method('supportsGrant')->willReturn(false);

        $this->clientService->method('validateClientCredentials')->willReturn($client);

        $request = Request::create('/oauth/token', 'POST', [
            'grant_type' => 'client_credentials',
            'client_id' => 'id',
            'client_secret' => 'secret',
        ]);

        $response = $this->grant->handle($request);

        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('unauthorized_client', $data['error']);
    }

    #[Test]
    public function returnsInvalidScopeWhenRequestedScopeUnknown(): void
    {
        $client = $this->createMock(OAuthClient::class);
        $client->method('supportsGrant')->willReturn(true);

        $this->clientService->method('validateClientCredentials')->willReturn($client);

        $request = Request::create('/oauth/token', 'POST', [
            'grant_type' => 'client_credentials',
            'client_id' => 'id',
            'client_secret' => 'secret',
            'scope' => 'unknown:scope',
        ]);

        $response = $this->grant->handle($request);

        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('invalid_scope', $data['error']);
    }

    #[Test]
    public function returnsTokenOnSuccess(): void
    {
        $client = $this->createMock(OAuthClient::class);
        $client->method('supportsGrant')->willReturn(true);
        $client->method('getAllowedScopes')->willReturn(['api:read']);
        $client->method('scopesAreAllowed')->willReturn(true);
        $client->method('getOrganization')->willReturn(null);

        $this->clientService->method('validateClientCredentials')->willReturn($client);

        $accessToken = $this->createMock(OAuthAccessToken::class);
        $refreshToken = $this->createMock(OAuthRefreshToken::class);
        $this->tokenService->method('issueTokenPair')
            ->willReturn([$accessToken, $refreshToken, 'plain_access_token', 'plain_refresh_token']);

        $request = Request::create('/oauth/token', 'POST', [
            'grant_type' => 'client_credentials',
            'client_id' => 'id',
            'client_secret' => 'secret',
        ]);

        $response = $this->grant->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('plain_access_token', $data['access_token']);
        $this->assertSame('Bearer', $data['token_type']);
        $this->assertSame('plain_refresh_token', $data['refresh_token']);
        $this->assertArrayHasKey('expires_in', $data);
    }

    #[Test]
    public function acceptsClientCredentialsViaHttpBasic(): void
    {
        $client = $this->createMock(OAuthClient::class);
        $client->method('supportsGrant')->willReturn(true);
        $client->method('getAllowedScopes')->willReturn(['api:read']);
        $client->method('scopesAreAllowed')->willReturn(true);
        $client->method('getOrganization')->willReturn(null);

        $this->clientService
            ->expects($this->once())
            ->method('validateClientCredentials')
            ->with('my_id', 'my_secret')
            ->willReturn($client);

        $accessToken = $this->createMock(OAuthAccessToken::class);
        $refreshToken = $this->createMock(OAuthRefreshToken::class);
        $this->tokenService->method('issueTokenPair')
            ->willReturn([$accessToken, $refreshToken, 'tok', 'ref']);

        $request = Request::create('/oauth/token', 'POST', ['grant_type' => 'client_credentials']);
        $request->headers->set('Authorization', 'Basic ' . base64_encode('my_id:my_secret'));

        $response = $this->grant->handle($request);

        $this->assertSame(200, $response->getStatusCode());
    }
}
