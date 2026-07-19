<?php

declare(strict_types=1);

namespace App\Tests\Functional\EventListener;

use App\Entity\OAuthClient;
use App\Entity\Organization;
use App\Service\OAuth\ClientService;
use App\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * config/packages/test/rate_limiter.yaml sets tiny limits (3/min per client,
 * 5/min per org) specifically so these tests can trip them without looping
 * dozens of times or mocking the clock.
 */
final class ApiRateLimitListenerTest extends FunctionalTestCase
{
    #[Test]
    public function itAllows429WithProblemDetailsAndRetryAfterOnceTheClientLimitIsExceeded(): void
    {
        $owner = $this->createUserWithOrg('ratelimit-client@example.com');
        [$client, $secret] = $this->createOAuthClient($owner->getOrganization());
        $token = $this->issueToken($client, $secret);

        for ($i = 0; $i < 3; ++$i) {
            $this->client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
            $this->assertResponseIsSuccessful();
        }

        $this->client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        $this->assertResponseStatusCodeSame(429);
        $this->assertSame('application/problem+json', $this->client->getResponse()->headers->get('Content-Type'));
        $this->assertTrue($this->client->getResponse()->headers->has('Retry-After'));
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame(429, $data['status']);
    }

    #[Test]
    public function itEnforcesTheOrgLimitAcrossMultipleClientsInTheSameOrg(): void
    {
        $owner = $this->createUserWithOrg('ratelimit-org@example.com');
        $org = $owner->getOrganization();

        [$clientA, $secretA] = $this->createOAuthClient($org, name: 'Client A');
        [$clientB, $secretB] = $this->createOAuthClient($org, name: 'Client B');
        $tokenA = $this->issueToken($clientA, $secretA);
        $tokenB = $this->issueToken($clientB, $secretB);

        // 5 requests total (2-3 per client) stays under each per-client limit (3/min)
        // but exactly exhausts the shared per-org limit (5/min); the 6th must reject.
        $this->client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokenA]);
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokenB]);
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokenA]);
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokenB]);
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokenA]);
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokenB]);

        $this->assertResponseStatusCodeSame(429);
    }

    /** @return array{0: OAuthClient, 1: string} */
    private function createOAuthClient(?Organization $org, string $name = 'Test App'): array
    {
        $this->assertNotNull($org);
        $clientService = static::getContainer()->get(ClientService::class);

        return $clientService->createClient(
            name: $name,
            grants: ['client_credentials'],
            scopes: ['api:read'],
            organization: $org,
        );
    }

    private function issueToken(OAuthClient $client, string $secret): string
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
