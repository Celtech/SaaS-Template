<?php

declare(strict_types=1);

namespace App\Tests\Functional\EventListener;

use App\Entity\OAuthClient;
use App\Entity\Organization;
use App\Service\OAuth\ClientService;
use App\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Lock\LockFactory;

final class IdempotencyKeyListenerTest extends FunctionalTestCase
{
    #[Test]
    public function itReplaysTheSameResponseForARepeatedIdempotencyKey(): void
    {
        $owner = $this->createUserWithOrg('idempotency-replay@example.com');
        [$client, $secret] = $this->createOAuthClient($owner->getOrganization());
        $token = $this->issueToken($client, $secret);
        $key = 'test-key-' . uniqid();

        $this->client->request('POST', '/api/v1/_test/idempotency', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_IDEMPOTENCY_KEY' => $key,
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertFalse($this->client->getResponse()->headers->has('Idempotent-Replayed'));
        $first = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->client->request('POST', '/api/v1/_test/idempotency', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_IDEMPOTENCY_KEY' => $key,
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSame('true', $this->client->getResponse()->headers->get('Idempotent-Replayed'));
        $second = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertSame($first['invocation_id'], $second['invocation_id']);
    }

    #[Test]
    public function itExecutesTheHandlerAgainForADifferentIdempotencyKey(): void
    {
        $owner = $this->createUserWithOrg('idempotency-different-key@example.com');
        [$client, $secret] = $this->createOAuthClient($owner->getOrganization());
        $token = $this->issueToken($client, $secret);

        $this->client->request('POST', '/api/v1/_test/idempotency', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_IDEMPOTENCY_KEY' => 'key-one-' . uniqid(),
        ]);
        $first = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->client->request('POST', '/api/v1/_test/idempotency', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_IDEMPOTENCY_KEY' => 'key-two-' . uniqid(),
        ]);
        $second = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertNotSame($first['invocation_id'], $second['invocation_id']);
    }

    #[Test]
    public function itExecutesTheHandlerAgainWithoutAnIdempotencyKey(): void
    {
        $owner = $this->createUserWithOrg('idempotency-no-key@example.com');
        [$client, $secret] = $this->createOAuthClient($owner->getOrganization());
        $token = $this->issueToken($client, $secret);

        $this->client->request('POST', '/api/v1/_test/idempotency', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $first = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->client->request('POST', '/api/v1/_test/idempotency', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $second = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertNotSame($first['invocation_id'], $second['invocation_id']);
    }

    #[Test]
    public function itScopesTheIdempotencyKeyPerOrganization(): void
    {
        $ownerA = $this->createUserWithOrg('idempotency-org-a@example.com', orgName: 'Org A');
        $ownerB = $this->createUserWithOrg('idempotency-org-b@example.com', orgName: 'Org B');
        [$clientA, $secretA] = $this->createOAuthClient($ownerA->getOrganization());
        [$clientB, $secretB] = $this->createOAuthClient($ownerB->getOrganization());
        $tokenA = $this->issueToken($clientA, $secretA);
        $tokenB = $this->issueToken($clientB, $secretB);
        $sharedKey = 'shared-key-' . uniqid();

        $this->client->request('POST', '/api/v1/_test/idempotency', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenA,
            'HTTP_IDEMPOTENCY_KEY' => $sharedKey,
        ]);
        $fromOrgA = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->client->request('POST', '/api/v1/_test/idempotency', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenB,
            'HTTP_IDEMPOTENCY_KEY' => $sharedKey,
        ]);
        $fromOrgB = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertNotSame($fromOrgA['invocation_id'], $fromOrgB['invocation_id']);
    }

    #[Test]
    public function itReturns409ForAConcurrentRequestWithTheSameKey(): void
    {
        $owner = $this->createUserWithOrg('idempotency-conflict@example.com');
        [$client, $secret] = $this->createOAuthClient($owner->getOrganization());
        $token = $this->issueToken($client, $secret);
        $key = 'conflict-key-' . uniqid();

        // Simulate an in-flight request by acquiring the lock directly, the
        // same way the listener would for a real request still being handled.
        // Cache key formula must match IdempotencyKeyListener::cacheKey() exactly.
        $organization = $owner->getOrganization();
        $this->assertNotNull($organization);
        $cacheKey = 'idempotency_' . hash('sha256', $organization->getId()->toRfc4122() . ':' . $key);

        $lockFactory = static::getContainer()->get(LockFactory::class);
        $lock = $lockFactory->createLock($cacheKey, 30.0, autoRelease: false);
        $this->assertTrue($lock->acquire());

        try {
            $this->client->request('POST', '/api/v1/_test/idempotency', server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'HTTP_IDEMPOTENCY_KEY' => $key,
            ]);

            $this->assertResponseStatusCodeSame(409);
            $this->assertSame('application/problem+json', $this->client->getResponse()->headers->get('Content-Type'));
        } finally {
            $lock->release();
        }
    }

    /** @return array{0: OAuthClient, 1: string} */
    private function createOAuthClient(?Organization $org): array
    {
        $this->assertNotNull($org);
        $clientService = static::getContainer()->get(ClientService::class);

        return $clientService->createClient(
            name: 'Test App',
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
