<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Webhook;

use App\Entity\WebhookEndpoint;
use App\Enum\WebhookEvent;
use App\Message\Webhook\DeliverWebhookMessage;
use App\Repository\WebhookDeliveryRepository;
use App\Service\Webhook\WebhookDispatcher;
use App\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class WebhookDispatcherTest extends FunctionalTestCase
{
    private WebhookDispatcher $dispatcher;
    private InMemoryTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatcher = static::getContainer()->get(WebhookDispatcher::class);
        $transport = static::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);
        $this->transport = $transport;
        $this->transport->reset();
    }

    #[Test]
    public function dispatchCreatesADeliveryAndQueuesItForEachSubscribedActiveEndpoint(): void
    {
        $user = $this->createUserWithOrg('webhook-dispatch@example.com');
        $org = $user->getOrganization();
        $this->assertNotNull($org);

        $endpoint = new WebhookEndpoint($org, 'https://example.com/hook', 'ciphertext', 'abcd', [WebhookEvent::OrgMemberInvited->value]);
        $this->em->persist($endpoint);
        $this->em->flush();

        $this->dispatcher->dispatch($org, WebhookEvent::OrgMemberInvited, ['email' => 'new@example.com']);

        $deliveries = static::getContainer()->get(WebhookDeliveryRepository::class)->findForEndpoint($endpoint);
        $this->assertCount(1, $deliveries);
        $this->assertSame(WebhookEvent::OrgMemberInvited->value, $deliveries[0]->getEventType());
        $this->assertSame(['email' => 'new@example.com'], $deliveries[0]->getPayload());

        $sent = $this->transport->getSent();
        $this->assertCount(1, $sent);
        $this->assertInstanceOf(DeliverWebhookMessage::class, $sent[0]->getMessage());
        $this->assertSame($deliveries[0]->getId()->toRfc4122(), $sent[0]->getMessage()->webhookDeliveryId);
    }

    #[Test]
    public function dispatchSkipsEndpointsNotSubscribedToTheEvent(): void
    {
        $user = $this->createUserWithOrg('webhook-dispatch-unsubscribed@example.com');
        $org = $user->getOrganization();
        $this->assertNotNull($org);

        $endpoint = new WebhookEndpoint($org, 'https://example.com/hook', 'ciphertext', 'abcd', [WebhookEvent::BillingPaymentFailed->value]);
        $this->em->persist($endpoint);
        $this->em->flush();

        $this->dispatcher->dispatch($org, WebhookEvent::OrgMemberInvited, []);

        $this->assertCount(0, static::getContainer()->get(WebhookDeliveryRepository::class)->findForEndpoint($endpoint));
        $this->assertCount(0, $this->transport->getSent());
    }

    #[Test]
    public function dispatchSkipsInactiveEndpoints(): void
    {
        $user = $this->createUserWithOrg('webhook-dispatch-inactive@example.com');
        $org = $user->getOrganization();
        $this->assertNotNull($org);

        $endpoint = new WebhookEndpoint($org, 'https://example.com/hook', 'ciphertext', 'abcd', [WebhookEvent::OrgMemberInvited->value]);
        $endpoint->setIsActive(false);
        $this->em->persist($endpoint);
        $this->em->flush();

        $this->dispatcher->dispatch($org, WebhookEvent::OrgMemberInvited, []);

        $this->assertCount(0, static::getContainer()->get(WebhookDeliveryRepository::class)->findForEndpoint($endpoint));
    }

    #[Test]
    public function sendTestQueuesADeliveryRegardlessOfSubscribedEvents(): void
    {
        $user = $this->createUserWithOrg('webhook-dispatch-test-event@example.com');
        $org = $user->getOrganization();
        $this->assertNotNull($org);

        $endpoint = new WebhookEndpoint($org, 'https://example.com/hook', 'ciphertext', 'abcd', [WebhookEvent::BillingPaymentFailed->value]);
        $this->em->persist($endpoint);
        $this->em->flush();

        $this->dispatcher->sendTest($endpoint);

        $deliveries = static::getContainer()->get(WebhookDeliveryRepository::class)->findForEndpoint($endpoint);
        $this->assertCount(1, $deliveries);
        $this->assertSame('test', $deliveries[0]->getEventType());
        $this->assertCount(1, $this->transport->getSent());
    }
}
