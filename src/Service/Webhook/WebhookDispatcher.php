<?php

declare(strict_types=1);

namespace App\Service\Webhook;

use App\Entity\Organization;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\Enum\WebhookEvent;
use App\Message\Webhook\DeliverWebhookMessage;
use App\Repository\WebhookDeliveryRepository;
use App\Repository\WebhookEndpointRepository;
use DateTimeImmutable;
use Symfony\Component\Messenger\MessageBusInterface;

/** Fans an event out to every active endpoint subscribed to it, one delivery + one async dispatch per endpoint. */
final class WebhookDispatcher
{
    public function __construct(
        private readonly WebhookEndpointRepository $endpoints,
        private readonly WebhookDeliveryRepository $deliveries,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function dispatch(Organization $organization, WebhookEvent $event, array $payload): void
    {
        $matchingEndpoints = $this->endpoints->findActiveForOrganizationAndEvent($organization, $event->value);

        foreach ($matchingEndpoints as $endpoint) {
            $delivery = new WebhookDelivery($endpoint, $event->value, $payload);
            $this->deliveries->save($delivery, flush: true);

            $this->bus->dispatch(new DeliverWebhookMessage($delivery->getId()->toRfc4122()));
        }
    }

    /** Sends a sample payload to this specific endpoint, bypassing its event subscriptions. */
    public function sendTest(WebhookEndpoint $endpoint): void
    {
        $delivery = new WebhookDelivery($endpoint, 'test', [
            'message' => 'This is a test webhook delivery.',
            'sent_at' => new DateTimeImmutable()->format(\DATE_ATOM),
        ]);
        $this->deliveries->save($delivery, flush: true);

        $this->bus->dispatch(new DeliverWebhookMessage($delivery->getId()->toRfc4122()));
    }
}
