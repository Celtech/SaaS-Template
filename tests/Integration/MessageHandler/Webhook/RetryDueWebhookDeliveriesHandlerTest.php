<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler\Webhook;

use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\Message\Webhook\DeliverWebhookMessage;
use App\Message\Webhook\RetryDueWebhookDeliveriesMessage;
use App\MessageHandler\Webhook\RetryDueWebhookDeliveriesHandler;
use App\Tests\FunctionalTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class RetryDueWebhookDeliveriesHandlerTest extends FunctionalTestCase
{
    private RetryDueWebhookDeliveriesHandler $handler;
    private InMemoryTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = static::getContainer()->get(RetryDueWebhookDeliveriesHandler::class);
        $transport = static::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);
        $this->transport = $transport;
        $this->transport->reset();
    }

    #[Test]
    public function requeuesDeliveriesWhoseBackoffHasElapsed(): void
    {
        $endpoint = $this->createEndpoint();

        $due = new WebhookDelivery($endpoint, 'org.member.invited', []);
        $due->recordFailure(500, 'error');
        $this->setNextAttemptAt($due, new DateTimeImmutable()->modify('-5 seconds'));
        $this->em->persist($due);
        $this->em->flush();

        ($this->handler)(new RetryDueWebhookDeliveriesMessage());

        $sent = $this->transport->getSent();
        $this->assertCount(1, $sent);
        $this->assertInstanceOf(DeliverWebhookMessage::class, $sent[0]->getMessage());
        $this->assertSame($due->getId()->toRfc4122(), $sent[0]->getMessage()->webhookDeliveryId);
    }

    #[Test]
    public function doesNotRequeueDeliveriesNotYetDue(): void
    {
        $endpoint = $this->createEndpoint();

        $notYetDue = new WebhookDelivery($endpoint, 'org.member.invited', []);
        $notYetDue->recordFailure(500, 'error'); // scheduled ~60s out by the real backoff
        $this->em->persist($notYetDue);
        $this->em->flush();

        ($this->handler)(new RetryDueWebhookDeliveriesMessage());

        $this->assertCount(0, $this->transport->getSent());
    }

    private function createEndpoint(): WebhookEndpoint
    {
        $user = $this->createUserWithOrg('webhook-retry-' . bin2hex(random_bytes(4)) . '@example.com');
        $org = $user->getOrganization();
        $this->assertNotNull($org);

        $endpoint = new WebhookEndpoint($org, 'https://example.com/hook', 'ciphertext', 'abcd', ['org.member.invited']);
        $this->em->persist($endpoint);
        $this->em->flush();

        return $endpoint;
    }

    private function setNextAttemptAt(WebhookDelivery $delivery, DateTimeImmutable $when): void
    {
        $property = new ReflectionProperty(WebhookDelivery::class, 'nextAttemptAt');
        $property->setValue($delivery, $when);
    }
}
