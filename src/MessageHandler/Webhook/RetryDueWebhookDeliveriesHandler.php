<?php

declare(strict_types=1);

namespace App\MessageHandler\Webhook;

use App\Message\Webhook\DeliverWebhookMessage;
use App\Message\Webhook\RetryDueWebhookDeliveriesMessage;
use App\Repository\WebhookDeliveryRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/** Sweeps deliveries whose backoff has elapsed and re-queues them for another attempt. */
#[AsMessageHandler]
final class RetryDueWebhookDeliveriesHandler
{
    public function __construct(
        private readonly WebhookDeliveryRepository $deliveries,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function __invoke(RetryDueWebhookDeliveriesMessage $message): void
    {
        foreach ($this->deliveries->findDue() as $delivery) {
            $this->bus->dispatch(new DeliverWebhookMessage($delivery->getId()->toRfc4122()));
        }
    }
}
