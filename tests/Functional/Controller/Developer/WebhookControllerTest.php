<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Developer;

use App\Entity\Organization;
use App\Entity\WebhookEndpoint;
use App\Service\Webhook\WebhookEndpointService;
use App\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class WebhookControllerTest extends FunctionalTestCase
{
    #[Test]
    public function indexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/developer/webhooks');

        $this->assertResponseRedirects('/auth/login');
    }

    #[Test]
    public function indexRendersForAuthenticatedUser(): void
    {
        $user = $this->createUserWithOrg('webhook-ctrl-index@example.com');
        $this->client->loginUser($user);

        $this->client->request('GET', '/developer/webhooks');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Webhooks');
    }

    #[Test]
    public function createEndpointIssuesOneTimeSecret(): void
    {
        $user = $this->createUserWithOrg('webhook-ctrl-create@example.com');
        $this->client->loginUser($user);

        $this->client->request('POST', '/developer/webhooks/new', [
            'webhook_endpoint' => [
                'url' => 'https://example.com/incoming',
                'events' => ['org.member.invited'],
                '_token' => $this->getCsrfToken('webhook_endpoint'),
            ],
        ]);

        $this->assertResponseRedirects();
        $this->client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'example.com/incoming');
        $this->assertSelectorTextContains('body', "won't be shown again");
    }

    #[Test]
    public function rejectsNonHttpsUrl(): void
    {
        $user = $this->createUserWithOrg('webhook-ctrl-http@example.com');
        $this->client->loginUser($user);

        $this->client->request('POST', '/developer/webhooks/new', [
            'webhook_endpoint' => [
                'url' => 'http://insecure.example.com/incoming',
                'events' => ['org.member.invited'],
                '_token' => $this->getCsrfToken('webhook_endpoint'),
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'Please fix the errors below');
    }

    #[Test]
    public function secretIsNotShownAgainOnRevisit(): void
    {
        $user = $this->createUserWithOrg('webhook-ctrl-secret-once@example.com');
        $endpoint = $this->createEndpoint($user->getOrganization());
        $this->client->loginUser($user);

        $this->client->request('GET', '/developer/webhooks/' . $endpoint->getId()->toRfc4122());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('body:contains("won\'t be shown again")');
    }

    #[Test]
    public function ownerCannotViewAnotherOrgsEndpoint(): void
    {
        $owner = $this->createUserWithOrg('webhook-ctrl-owner@example.com');
        $endpoint = $this->createEndpoint($owner->getOrganization());

        $intruder = $this->createUserWithOrg('webhook-ctrl-intruder@example.com');
        $this->client->loginUser($intruder);

        $this->client->request('GET', '/developer/webhooks/' . $endpoint->getId()->toRfc4122());

        $this->assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function regenerateSecretInvalidatesPreviousSecret(): void
    {
        $user = $this->createUserWithOrg('webhook-ctrl-regen@example.com');
        $org = $user->getOrganization();
        $this->assertNotNull($org);
        $endpointService = static::getContainer()->get(WebhookEndpointService::class);
        [$endpoint, $originalSecret] = $endpointService->createEndpoint($org, 'https://example.com/incoming', ['org.member.invited']);

        $this->client->loginUser($user);
        $this->client->request('POST', '/developer/webhooks/' . $endpoint->getId()->toRfc4122() . '/regenerate-secret', [
            '_token' => $this->getCsrfToken('regenerate_webhook_secret_' . $endpoint->getId()->toRfc4122()),
        ]);

        $this->assertResponseRedirects('/developer/webhooks/' . $endpoint->getId()->toRfc4122());

        $this->em->clear();
        $refreshed = $this->em->find(WebhookEndpoint::class, $endpoint->getId());
        $this->assertNotNull($refreshed);
        $currentSecret = $endpointService->getPlainSecret($refreshed);
        $this->assertNotSame($originalSecret, $currentSecret);
    }

    #[Test]
    public function testEndpointQueuesADelivery(): void
    {
        $user = $this->createUserWithOrg('webhook-ctrl-test@example.com');
        $endpoint = $this->createEndpoint($user->getOrganization());
        $this->client->loginUser($user);

        $this->client->request('POST', '/developer/webhooks/' . $endpoint->getId()->toRfc4122() . '/test', [
            '_token' => $this->getCsrfToken('test_webhook_' . $endpoint->getId()->toRfc4122()),
        ]);

        $this->assertResponseRedirects('/developer/webhooks/' . $endpoint->getId()->toRfc4122());
        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'Test event queued');
    }

    #[Test]
    public function deleteEndpointRemovesIt(): void
    {
        $user = $this->createUserWithOrg('webhook-ctrl-delete@example.com');
        $endpoint = $this->createEndpoint($user->getOrganization());
        $endpointId = $endpoint->getId()->toRfc4122();
        $this->client->loginUser($user);

        $this->client->request('POST', '/developer/webhooks/' . $endpointId . '/delete', [
            '_token' => $this->getCsrfToken('delete_webhook_' . $endpointId),
        ]);

        $this->assertResponseRedirects('/developer/webhooks');
        $this->client->request('GET', '/developer/webhooks/' . $endpointId);
        $this->assertResponseStatusCodeSame(404);
    }

    private function createEndpoint(?Organization $org): WebhookEndpoint
    {
        $this->assertNotNull($org);
        $endpointService = static::getContainer()->get(WebhookEndpointService::class);
        [$endpoint] = $endpointService->createEndpoint($org, 'https://example.com/incoming', ['org.member.invited']);

        return $endpoint;
    }

    private function getCsrfToken(string $tokenId): string
    {
        return static::getContainer()
            ->get(CsrfTokenManagerInterface::class)
            ->getToken($tokenId)
            ->getValue();
    }
}
