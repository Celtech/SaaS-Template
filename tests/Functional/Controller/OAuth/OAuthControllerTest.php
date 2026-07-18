<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\OAuth;

use App\Entity\OAuthAuthorizationCode;
use App\Entity\OAuthClient;
use App\Entity\OAuthDeviceCode;
use App\Entity\Organization;
use App\Service\OAuth\ClientService;
use App\Service\OAuth\DeviceCodeService;
use App\Service\OAuth\PkceVerifier;
use App\Service\OAuth\TokenGenerator;
use App\Tests\FunctionalTestCase;
use DateTimeImmutable;
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

    #[Test]
    public function authorizationCodeGrantExchangesCodeForTokens(): void
    {
        $owner = $this->createUserWithOrg('oauth-authz-exchange@example.com');
        [$client, $secret] = $this->createOAuthClient(
            $owner->getOrganization(),
            grants: ['authorization_code', 'refresh_token'],
            redirectUris: ['https://app.example.com/callback'],
        );
        [$plainCode, $verifier] = $this->createAuthorizationCode($client, $owner, 'https://app.example.com/callback');

        $this->client->request('POST', '/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $plainCode,
            'redirect_uri' => 'https://app.example.com/callback',
            'code_verifier' => $verifier,
            'client_id' => $client->getClientId(),
            'client_secret' => $secret,
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('access_token', $data);
        $this->assertArrayHasKey('refresh_token', $data);
    }

    #[Test]
    public function authorizationCodeGrantRejectsWrongVerifier(): void
    {
        $owner = $this->createUserWithOrg('oauth-authz-wrong-verifier@example.com');
        [$client, $secret] = $this->createOAuthClient(
            $owner->getOrganization(),
            grants: ['authorization_code'],
            redirectUris: ['https://app.example.com/callback'],
        );
        [$plainCode] = $this->createAuthorizationCode($client, $owner, 'https://app.example.com/callback');

        $this->client->request('POST', '/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $plainCode,
            'redirect_uri' => 'https://app.example.com/callback',
            'code_verifier' => 'the-wrong-verifier',
            'client_id' => $client->getClientId(),
            'client_secret' => $secret,
        ]);

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('invalid_grant', $data['error']);
    }

    #[Test]
    public function authorizationCodeGrantRejectsMismatchedRedirectUri(): void
    {
        $owner = $this->createUserWithOrg('oauth-authz-wrong-redirect@example.com');
        [$client, $secret] = $this->createOAuthClient(
            $owner->getOrganization(),
            grants: ['authorization_code'],
            redirectUris: ['https://app.example.com/callback', 'https://app.example.com/other'],
        );
        [$plainCode, $verifier] = $this->createAuthorizationCode($client, $owner, 'https://app.example.com/callback');

        $this->client->request('POST', '/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $plainCode,
            'redirect_uri' => 'https://app.example.com/other',
            'code_verifier' => $verifier,
            'client_id' => $client->getClientId(),
            'client_secret' => $secret,
        ]);

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('invalid_grant', $data['error']);
    }

    #[Test]
    public function authorizationCodeGrantRejectsReusedCode(): void
    {
        $owner = $this->createUserWithOrg('oauth-authz-reuse@example.com');
        [$client, $secret] = $this->createOAuthClient(
            $owner->getOrganization(),
            grants: ['authorization_code'],
            redirectUris: ['https://app.example.com/callback'],
        );
        [$plainCode, $verifier] = $this->createAuthorizationCode($client, $owner, 'https://app.example.com/callback');

        $params = [
            'grant_type' => 'authorization_code',
            'code' => $plainCode,
            'redirect_uri' => 'https://app.example.com/callback',
            'code_verifier' => $verifier,
            'client_id' => $client->getClientId(),
            'client_secret' => $secret,
        ];

        $this->client->request('POST', '/oauth/token', $params);
        $this->assertResponseIsSuccessful();

        $this->client->request('POST', '/oauth/token', $params);
        $this->assertResponseStatusCodeSame(400);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('invalid_grant', $data['error']);
    }

    #[Test]
    public function authorizationCodeGrantRejectsCodeIssuedToAnotherClient(): void
    {
        $owner = $this->createUserWithOrg('oauth-authz-cross-client@example.com');
        [$clientA] = $this->createOAuthClient(
            $owner->getOrganization(),
            grants: ['authorization_code'],
            redirectUris: ['https://app.example.com/callback'],
            name: 'Client A',
        );
        [$clientB, $secretB] = $this->createOAuthClient(
            $owner->getOrganization(),
            grants: ['authorization_code'],
            redirectUris: ['https://app.example.com/callback'],
            name: 'Client B',
        );
        [$plainCode, $verifier] = $this->createAuthorizationCode($clientA, $owner, 'https://app.example.com/callback');

        $this->client->request('POST', '/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $plainCode,
            'redirect_uri' => 'https://app.example.com/callback',
            'code_verifier' => $verifier,
            'client_id' => $clientB->getClientId(),
            'client_secret' => $secretB,
        ]);

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('invalid_grant', $data['error']);
    }

    #[Test]
    public function deviceAuthorizationIssuesDeviceAndUserCode(): void
    {
        $owner = $this->createUserWithOrg('oauth-device-issue@example.com');
        [$client, $secret] = $this->createOAuthClient(
            $owner->getOrganization(),
            grants: ['urn:ietf:params:oauth:grant-type:device_code'],
        );

        $this->client->request('POST', '/oauth/device/authorization', [
            'client_id' => $client->getClientId(),
            'client_secret' => $secret,
            'scope' => 'api:read',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('device_code', $data);
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $data['user_code']);
        $this->assertStringEndsWith('/oauth/device', $data['verification_uri']);
        $this->assertStringContainsString('user_code=' . $data['user_code'], $data['verification_uri_complete']);
        $this->assertSame(5, $data['interval']);
    }

    #[Test]
    public function deviceAuthorizationRejectsClientWithoutGrant(): void
    {
        $owner = $this->createUserWithOrg('oauth-device-unauthorized@example.com');
        [$client, $secret] = $this->createOAuthClient($owner->getOrganization(), grants: ['client_credentials']);

        $this->client->request('POST', '/oauth/device/authorization', [
            'client_id' => $client->getClientId(),
            'client_secret' => $secret,
        ]);

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('unauthorized_client', $data['error']);
    }

    #[Test]
    public function deviceCodeGrantReturnsAuthorizationPendingBeforeApproval(): void
    {
        $owner = $this->createUserWithOrg('oauth-device-pending@example.com');
        [$client, $secret] = $this->createOAuthClient(
            $owner->getOrganization(),
            grants: ['urn:ietf:params:oauth:grant-type:device_code'],
        );
        [, $plainDeviceCode] = $this->createDeviceCode($client);

        $this->client->request('POST', '/oauth/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'device_code' => $plainDeviceCode,
            'client_id' => $client->getClientId(),
            'client_secret' => $secret,
        ]);

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('authorization_pending', $data['error']);
    }

    #[Test]
    public function deviceCodeGrantReturnsSlowDownWhenPollingTooFast(): void
    {
        $owner = $this->createUserWithOrg('oauth-device-slowdown@example.com');
        [$client, $secret] = $this->createOAuthClient(
            $owner->getOrganization(),
            grants: ['urn:ietf:params:oauth:grant-type:device_code'],
        );
        [, $plainDeviceCode] = $this->createDeviceCode($client);

        $params = [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'device_code' => $plainDeviceCode,
            'client_id' => $client->getClientId(),
            'client_secret' => $secret,
        ];

        $this->client->request('POST', '/oauth/token', $params);
        $this->assertSame('authorization_pending', json_decode((string) $this->client->getResponse()->getContent(), true)['error']);

        $this->client->request('POST', '/oauth/token', $params);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('slow_down', $data['error']);
    }

    #[Test]
    public function deviceCodeGrantSucceedsAfterApprovalAndRejectsReuse(): void
    {
        $owner = $this->createUserWithOrg('oauth-device-approved@example.com');
        [$client, $secret] = $this->createOAuthClient(
            $owner->getOrganization(),
            grants: ['urn:ietf:params:oauth:grant-type:device_code', 'refresh_token'],
        );
        [$deviceCode, $plainDeviceCode] = $this->createDeviceCode($client);

        $deviceCodeService = static::getContainer()->get(DeviceCodeService::class);
        $deviceCodeService->approve($deviceCode, $owner, $owner->getOrganization());

        $params = [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'device_code' => $plainDeviceCode,
            'client_id' => $client->getClientId(),
            'client_secret' => $secret,
        ];

        $this->client->request('POST', '/oauth/token', $params);
        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('access_token', $data);
        $this->assertArrayHasKey('refresh_token', $data);

        // The device_code is single-use: a second exchange must fail.
        $this->client->request('POST', '/oauth/token', $params);
        $this->assertResponseStatusCodeSame(400);
        $second = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('invalid_grant', $second['error']);
    }

    #[Test]
    public function deviceCodeGrantReturnsAccessDeniedAfterDenial(): void
    {
        $owner = $this->createUserWithOrg('oauth-device-denied@example.com');
        [$client, $secret] = $this->createOAuthClient(
            $owner->getOrganization(),
            grants: ['urn:ietf:params:oauth:grant-type:device_code'],
        );
        [$deviceCode, $plainDeviceCode] = $this->createDeviceCode($client);

        $deviceCodeService = static::getContainer()->get(DeviceCodeService::class);
        $deviceCodeService->deny($deviceCode);

        $this->client->request('POST', '/oauth/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'device_code' => $plainDeviceCode,
            'client_id' => $client->getClientId(),
            'client_secret' => $secret,
        ]);

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('access_denied', $data['error']);
    }

    #[Test]
    public function deviceCodeGrantRejectsCodeIssuedToAnotherClient(): void
    {
        $owner = $this->createUserWithOrg('oauth-device-cross-client@example.com');
        [$clientA] = $this->createOAuthClient(
            $owner->getOrganization(),
            grants: ['urn:ietf:params:oauth:grant-type:device_code'],
            name: 'Device Client A',
        );
        [$clientB, $secretB] = $this->createOAuthClient(
            $owner->getOrganization(),
            grants: ['urn:ietf:params:oauth:grant-type:device_code'],
            name: 'Device Client B',
        );
        [, $plainDeviceCode] = $this->createDeviceCode($clientA);

        $this->client->request('POST', '/oauth/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'device_code' => $plainDeviceCode,
            'client_id' => $clientB->getClientId(),
            'client_secret' => $secretB,
        ]);

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('invalid_grant', $data['error']);
    }

    /**
     * @param  string[]  $grants
     * @param  string[]  $redirectUris
     *
     * @return array{0: OAuthClient, 1: string}
     */
    private function createOAuthClient(
        ?Organization $org,
        array $grants,
        array $redirectUris = [],
        string $name = 'Test App',
    ): array {
        $this->assertNotNull($org);
        $clientService = static::getContainer()->get(ClientService::class);

        return $clientService->createClient(
            name: $name,
            grants: $grants,
            scopes: ['api:read'],
            redirectUris: $redirectUris,
            organization: $org,
        );
    }

    /** @return array{0: string, 1: string} plain code, code verifier */
    private function createAuthorizationCode(OAuthClient $client, \App\Entity\User $user, string $redirectUri): array
    {
        $generator = static::getContainer()->get(TokenGenerator::class);
        $pkce = static::getContainer()->get(PkceVerifier::class);

        $verifier = $generator->generateToken();
        $plainCode = $generator->generateToken();

        $authorizationCode = new OAuthAuthorizationCode(
            codeHash: $generator->hashToken($plainCode),
            client: $client,
            user: $user,
            organization: $user->getOrganization(),
            scopes: ['api:read'],
            redirectUri: $redirectUri,
            codeChallenge: $pkce->challengeFromVerifier($verifier),
            expiresAt: new DateTimeImmutable()->modify('+60 seconds'),
        );
        $this->em->persist($authorizationCode);
        $this->em->flush();

        return [$plainCode, $verifier];
    }

    private function issueTokenPair(OAuthClient $client, string $secret): string
    {
        $this->client->request('POST', '/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->getClientId(),
            'client_secret' => $secret,
        ]);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $data['refresh_token'];
    }

    /** @return array{0: OAuthDeviceCode, 1: string} entity, plain device_code */
    private function createDeviceCode(OAuthClient $client): array
    {
        $deviceCodeService = static::getContainer()->get(DeviceCodeService::class);

        [$deviceCode, $plainDeviceCode] = $deviceCodeService->issue($client, ['api:read']);

        return [$deviceCode, $plainDeviceCode];
    }
}
