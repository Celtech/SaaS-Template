<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\OAuth;

use App\Service\OAuth\ClientService;
use App\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

final class OAuthControllerTest extends FunctionalTestCase
{
    #[Test]
    public function discoveryReturnsCorrectlyFormedUrls(): void
    {
        $this->client->request('GET', '/.well-known/oauth-authorization-server');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertIsString($data['issuer']);
        $this->assertStringStartsWith('http', $data['issuer']);
        $this->assertStringEndsNotWith('.well-known/oauth-authorization-server', $data['issuer']);
        $this->assertSame($data['issuer'] . '/oauth/token', $data['token_endpoint']);
        $this->assertSame($data['issuer'] . '/oauth/revoke', $data['revocation_endpoint']);
        $this->assertSame($data['issuer'] . '/oauth/introspect', $data['introspection_endpoint']);
    }

    #[Test]
    public function clientCredentialsGrantIssuesTokenPair(): void
    {
        $owner = $this->createUserWithOrg('oauth-cc@example.com');
        [$client, $secret] = $this->createOAuthClient($owner->getOrganization(), grants: ['client_credentials', 'refresh_token']);

        $this->client->request('POST', '/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->getClientId(),
            'client_secret' => $secret,
            'scope' => 'api:read',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('access_token', $data);
        $this->assertArrayHasKey('refresh_token', $data);
        $this->assertSame('Bearer', $data['token_type']);
    }

    #[Test]
    public function clientCredentialsGrantOmitsRefreshTokenWhenNotSupported(): void
    {
        $owner = $this->createUserWithOrg('oauth-cc-norefresh@example.com');
        [$client, $secret] = $this->createOAuthClient($owner->getOrganization(), grants: ['client_credentials']);

        $this->client->request('POST', '/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->getClientId(),
            'client_secret' => $secret,
            'scope' => 'api:read',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayNotHasKey('refresh_token', $data);
    }

    #[Test]
    public function clientCredentialsGrantRejectsInvalidSecret(): void
    {
        $owner = $this->createUserWithOrg('oauth-cc-badsecret@example.com');
        [$client] = $this->createOAuthClient($owner->getOrganization(), grants: ['client_credentials']);

        $this->client->request('POST', '/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->getClientId(),
            'client_secret' => 'wrong-secret',
        ]);

        $this->assertResponseStatusCodeSame(401);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('invalid_client', $data['error']);
    }

    #[Test]
    public function tokenEndpointRejectsUnsupportedGrantType(): void
    {
        $this->client->request('POST', '/oauth/token', ['grant_type' => 'password']);

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('unsupported_grant_type', $data['error']);
    }

    #[Test]
    public function refreshTokenGrantRequiresClientAuthentication(): void
    {
        $owner = $this->createUserWithOrg('oauth-refresh-noauth@example.com');
        [$client, $secret] = $this->createOAuthClient($owner->getOrganization(), grants: ['client_credentials', 'refresh_token']);
        $refreshToken = $this->issueTokenPair($client, $secret);

        $this->client->request('POST', '/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        $this->assertResponseStatusCodeSame(401);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('invalid_client', $data['error']);
    }

    #[Test]
    public function refreshTokenGrantRejectsTokenIssuedToAnotherClient(): void
    {
        $owner = $this->createUserWithOrg('oauth-refresh-crossclient@example.com');
        [$clientA, $secretA] = $this->createOAuthClient($owner->getOrganization(), grants: ['client_credentials', 'refresh_token'], name: 'Client A');
        [$clientB, $secretB] = $this->createOAuthClient($owner->getOrganization(), grants: ['client_credentials', 'refresh_token'], name: 'Client B');
        $refreshToken = $this->issueTokenPair($clientA, $secretA);

        $this->client->request('POST', '/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientB->getClientId(),
            'client_secret' => $secretB,
        ]);

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('invalid_grant', $data['error']);
    }

    #[Test]
    public function refreshTokenGrantRotatesTokenWithCorrectClient(): void
    {
        $owner = $this->createUserWithOrg('oauth-refresh-ok@example.com');
        [$client, $secret] = $this->createOAuthClient($owner->getOrganization(), grants: ['client_credentials', 'refresh_token']);
        $refreshToken = $this->issueTokenPair($client, $secret);

        $this->client->request('POST', '/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $client->getClientId(),
            'client_secret' => $secret,
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('access_token', $data);
        $this->assertArrayHasKey('refresh_token', $data);
        $this->assertNotSame($refreshToken, $data['refresh_token']);

        // Old refresh token is now revoked and cannot be reused.
        $this->client->request('POST', '/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $client->getClientId(),
            'client_secret' => $secret,
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function revokeAndIntrospectReflectTokenState(): void
    {
        $owner = $this->createUserWithOrg('oauth-revoke@example.com');
        [$client, $secret] = $this->createOAuthClient($owner->getOrganization(), grants: ['client_credentials']);

        $this->client->request('POST', '/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->getClientId(),
            'client_secret' => $secret,
        ]);
        $token = json_decode((string) $this->client->getResponse()->getContent(), true)['access_token'];

        $this->client->request('POST', '/oauth/introspect', ['token' => $token]);
        $this->assertTrue(json_decode((string) $this->client->getResponse()->getContent(), true)['active']);

        $this->client->request('POST', '/oauth/revoke', ['token' => $token]);
        $this->assertResponseIsSuccessful();

        $this->client->request('POST', '/oauth/introspect', ['token' => $token]);
        $this->assertFalse(json_decode((string) $this->client->getResponse()->getContent(), true)['active']);
    }

    #[Test]
    public function introspectReturnsInactiveForUnknownToken(): void
    {
        $this->client->request('POST', '/oauth/introspect', ['token' => 'not-a-real-token']);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertFalse($data['active']);
    }

    /**
     * @param  string[]  $grants
     *
     * @return array{0: \App\Entity\OAuthClient, 1: string}
     */
    private function createOAuthClient(
        ?\App\Entity\Organization $org,
        array $grants,
        string $name = 'Test App',
    ): array {
        $this->assertNotNull($org);
        $clientService = static::getContainer()->get(ClientService::class);

        return $clientService->createClient(
            name: $name,
            grants: $grants,
            scopes: ['api:read'],
            organization: $org,
        );
    }

    private function issueTokenPair(\App\Entity\OAuthClient $client, string $secret): string
    {
        $this->client->request('POST', '/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->getClientId(),
            'client_secret' => $secret,
        ]);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $data['refresh_token'];
    }
}
