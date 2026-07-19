<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api\V1;

use App\Entity\OAuthClient;
use App\Entity\Organization;
use App\Service\OAuth\ClientService;
use App\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

final class OrganizationsControllerTest extends FunctionalTestCase
{
    #[Test]
    public function itReturns401ProblemDetailsWithoutAToken(): void
    {
        $this->client->request('GET', '/api/v1/organizations');

        $this->assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function itReturns403WhenTokenLacksOrgReadScope(): void
    {
        $owner = $this->createUserWithOrg('orgs-no-scope@example.com');
        [$client, $secret] = $this->createOAuthClient($owner->getOrganization(), scopes: ['api:read']);
        $token = $this->issueClientCredentialsToken($client, $secret);

        $this->client->request('GET', '/api/v1/organizations', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        $this->assertResponseStatusCodeSame(403);
        $this->assertSame('application/problem+json', $this->client->getResponse()->headers->get('Content-Type'));
    }

    #[Test]
    public function itReturnsTheTokensOrganizationWhenScopeIsGranted(): void
    {
        $owner = $this->createUserWithOrg('orgs-with-scope@example.com', orgName: 'Acme Inc');
        [$client, $secret] = $this->createOAuthClient($owner->getOrganization(), scopes: ['org:read']);
        $token = $this->issueClientCredentialsToken($client, $secret);

        $this->client->request('GET', '/api/v1/organizations', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertCount(1, $data['data']);
        $organization = $owner->getOrganization();
        $this->assertNotNull($organization);
        $this->assertSame($organization->getId()->toRfc4122(), $data['data'][0]['id']);
        $this->assertSame('Acme Inc', $data['data'][0]['name']);
    }

    /**
     * @param  string[]  $scopes
     *
     * @return array{0: OAuthClient, 1: string}
     */
    private function createOAuthClient(?Organization $org, array $scopes): array
    {
        $this->assertNotNull($org);
        $clientService = static::getContainer()->get(ClientService::class);

        return $clientService->createClient(
            name: 'Test App',
            grants: ['client_credentials'],
            scopes: $scopes,
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
}
