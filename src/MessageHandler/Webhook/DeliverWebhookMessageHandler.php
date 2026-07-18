<?php

declare(strict_types=1);

namespace App\MessageHandler\Webhook;

use App\Enum\WebhookDeliveryStatus;
use App\Message\Webhook\DeliverWebhookMessage;
use App\Repository\WebhookDeliveryRepository;
use App\Service\Webhook\WebhookEndpointService;
use App\Service\Webhook\WebhookSigner;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsMessageHandler]
final class DeliverWebhookMessageHandler
{
    private const REQUEST_TIMEOUT_SECONDS = 10;
    private const MAX_DURATION_SECONDS = 15;

    public function __construct(
        private readonly WebhookDeliveryRepository $deliveries,
        private readonly WebhookEndpointService $endpointService,
        private readonly WebhookSigner $signer,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(DeliverWebhookMessage $message): void
    {
        $delivery = $this->deliveries->find(Uuid::fromString($message->webhookDeliveryId));

        if ($delivery === null) {
            return;
        }

        $endpoint = $delivery->getEndpoint();

        if (!$endpoint->isActive()) {
            return;
        }

        $body = json_encode([
            'event' => $delivery->getEventType(),
            'data' => $delivery->getPayload(),
            'delivery_id' => $delivery->getId()->toRfc4122(),
            'timestamp' => new DateTimeImmutable()->format(\DATE_ATOM),
        ], \JSON_THROW_ON_ERROR);

        $secret = $this->endpointService->getPlainSecret($endpoint);
        $signature = $this->signer->sign($body, $secret);

        try {
            $response = $this->httpClient->request('POST', $endpoint->getUrl(), [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Webhook-Signature' => $signature,
                    'X-Webhook-Event' => $delivery->getEventType(),
                ],
                'body' => $body,
                'timeout' => self::REQUEST_TIMEOUT_SECONDS,
                'max_duration' => self::MAX_DURATION_SECONDS,
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = $response->getContent(false);

            if ($statusCode >= 200 && $statusCode < 300) {
                $delivery->recordSuccess($statusCode, $responseBody);
            } else {
                $delivery->recordFailure($statusCode, $responseBody);
            }
        } catch (TransportExceptionInterface $e) {
            $delivery->recordFailure(null, $e->getMessage());
        }

        $this->deliveries->save($delivery, flush: true);

        if ($delivery->getStatus() === WebhookDeliveryStatus::Exhausted) {
            $this->logger->warning('Webhook delivery exhausted all retry attempts', [
                'delivery_id' => $delivery->getId()->toRfc4122(),
                'endpoint_id' => $endpoint->getId()->toRfc4122(),
                'event' => $delivery->getEventType(),
            ]);
        }
    }
}
