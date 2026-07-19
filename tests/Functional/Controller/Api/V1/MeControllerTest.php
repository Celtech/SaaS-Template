<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api\V1;

use App\Entity\OAuthAuthorizationCode;
use App\Entity\OAuthClient;
use App\Entity\Organization;
use App\Entity\User;
use App\Service\OAuth\ClientService;
use App\Service\OAuth\PkceVerifier;
use App\Service\OAuth\TokenGenerator;
use App\Tests\FunctionalTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;

final class MeControllerTest extends FunctionalTestCase
{
    #[Test]
    public function itReturns401ProblemDetailsWithoutAToken(): void
    {
        $this->client->request('GET', '/api/v1/me');

        $this->assertResponseStatusCodeSame(401);
        $this->assertSame('application/problem+json', $this->client->getResponse()->headers->get('Content-Type'));
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame(401, $data['status']);
    }

    #[Test]
    public function itReturns401WithAnInvalidToken(): void
    {
        $this->client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer not-a-real-token']);

        $this->assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function itReturnsTheClientIdentityForAClientCredentialsToken(): void
    {
        $owner = $this->createUserWithOrg('me-cc@example.com');
        [$client, $secret] = $this->createOAuthClient($owner->getOrganization(), grants: ['client_credentials'], scopes: ['api:read']);
        $token = $this->issueClientCredentialsToken($client, $secret);

        $this->client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('client', $data['data']['type']);
        $this->assertSame($client->getClientId(), $data['data']['id']);
        $this->assertSame('Test App', $data['data']['name']);
        $organization = $owner->getOrganization();
        $this->assertNotNull($organization);
        $this->assertSame($organization->getId()->toRfc4122(), $data['data']['organization_id']);
    }

    #[Test]
    public function itReturnsFullUserIdentityWhenProfileAndEmailScopesAreGranted(): void
    {
        $user = $this->createUserWithOrg('me-user-full@example.com');
        [$client, $secret] = $this->createOAuthClient($user->getOrganization(), grants: ['authorization_code'], scopes: ['openid', 'profile', 'email'], redirectUris: ['https://example.com/cb']);
        $token = $this->issueDelegatedToken($client, $secret, $user, ['openid', 'profile', 'email']);

        $this->client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('user', $data['data']['type']);
        $this->assertSame($user->getId()->toRfc4122(), $data['data']['id']);
        $this->assertSame($user->getName(), $data['data']['name']);
        $this->assertSame($user->getEmail(), $data['data']['email']);
    }

    #[Test]
    public function itOmitsNameAndEmailWhenOnlyOpenIdScopeIsGranted(): void
    {
        $user = $this->createUserWithOrg('me-user-minimal@example.com');
        [$client, $secret] = $this->createOAuthClient($user->getOrganization(), grants: ['authorization_code'], scopes: ['openid'], redirectUris: ['https://example.com/cb']);
        $token = $this->issueDelegatedToken($client, $secret, $user, ['openid']);

        $this->client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('user', $data['data']['type']);
        $this->assertArrayNotHasKey('name', $data['data']);
        $this->assertArrayNotHasKey('email', $data['data']);
    }

    /**
     * @param  string[]  $grants
     * @param  string[]  $scopes
     * @param  string[]  $redirectUris
     *
     * @return array{0: OAuthClient, 1: string}
     */
    private function createOAuthClient(?Organization $org, array $grants, array $scopes, array $redirectUris = []): array
    {
        $this->assertNotNull($org);
        $clientService = static::getContainer()->get(ClientService::class);

        return $clientService->createClient(
            name: 'Test App',
            grants: $grants,
            scopes: $scopes,
            redirectUris: $redirectUris,
            organization: $org,
        );
    }

    private function issueClientCredentialsToken(OAuthClient $client, string $secret): string
    {
        $this->client->request('POST', '/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->getClientId(),
            'client_secret' => $secret,
        ]);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $data['access_token'];
    }

    /** @param string[] $scopes */
    private function issueDelegatedToken(OAuthClient $client, string $secret, User $user, array $scopes): string
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
            scopes: $scopes,
            redirectUri: 'https://example.com/cb',
            codeChallenge: $pkce->challengeFromVerifier($verifier),
            expiresAt: new DateTimeImmutable()->modify('+60 seconds'),
        );
        $this->em->persist($authorizationCode);
        $this->em->flush();

        $this->client->request('POST', '/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $plainCode,
            'redirect_uri' => 'https://example.com/cb',
            'code_verifier' => $verifier,
            'client_id' => $client->getClientId(),
            'client_secret' => $secret,
        ]);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $data['access_token'];
    }
}
