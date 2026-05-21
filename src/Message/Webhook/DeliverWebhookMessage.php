<?php

declare(strict_types=1);

namespace App\Message\Webhook;

final readonly class DeliverWebhookMessage
{
    public function __construct(
        public string $webhookDeliveryId,
    ) {
    }
}
