<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler\Webhook;

use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\Enum\WebhookDeliveryStatus;
use App\Message\Webhook\DeliverWebhookMessage;
use App\MessageHandler\Webhook\DeliverWebhookMessageHandler;
use App\Repository\WebhookDeliveryRepository;
use App\Service\Webhook\WebhookEndpointService;
use App\Service\Webhook\WebhookSigner;
use App\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DeliverWebhookMessageHandlerTest extends FunctionalTestCase
{
    #[Test]
    public function successfulDeliveryRecordsResponseAndSignsTheBody(): void
    {
        [$endpoint, $secret] = $this->createEndpoint();
        $delivery = $this->createDelivery($endpoint);

        $capturedSignature = null;
        $capturedBody = null;
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedSignature, &$capturedBody) {
            $capturedSignature = $options['normalized_headers']['x-webhook-signature'][0] ?? null;
            $capturedBody = $options['body'];

            return new MockResponse('{"ok":true}', ['http_code' => 200]);
        });

        $this->invokeHandler($httpClient, new DeliverWebhookMessage($delivery->getId()->toRfc4122()));

        $this->em->clear();
        $refreshed = $this->em->find(WebhookDelivery::class, $delivery->getId());
        $this->assertNotNull($refreshed);
        $this->assertSame(WebhookDeliveryStatus::Success, $refreshed->getStatus());
        $this->assertSame(200, $refreshed->getLastResponseCode());
        $this->assertSame('{"ok":true}', $refreshed->getLastResponseBody());
        $this->assertSame(1, $refreshed->getAttempts());

        $this->assertNotNull($capturedSignature);
        $expectedSignature = new WebhookSigner()->sign((string) $capturedBody, $secret);
        $this->assertStringContainsString($expectedSignature, (string) $capturedSignature);
    }

    #[Test]
    public function failedDeliverySchedulesARetry(): void
    {
        [$endpoint] = $this->createEndpoint();
        $delivery = $this->createDelivery($endpoint);

        $httpClient = new MockHttpClient(static fn () => new MockResponse('server error', ['http_code' => 500]));

        $this->invokeHandler($httpClient, new DeliverWebhookMessage($delivery->getId()->toRfc4122()));

        $this->em->clear();
        $refreshed = $this->em->find(WebhookDelivery::class, $delivery->getId());
        $this->assertNotNull($refreshed);
        $this->assertSame(WebhookDeliveryStatus::Failed, $refreshed->getStatus());
        $this->assertSame(500, $refreshed->getLastResponseCode());
        $this->assertNotNull($refreshed->getNextAttemptAt());
    }

    #[Test]
    public function transportExceptionIsTreatedAsAFailure(): void
    {
        [$endpoint] = $this->createEndpoint();
        $delivery = $this->createDelivery($endpoint);

        $httpClient = new MockHttpClient(static function (): void {
            throw new TransportException('Connection refused');
        });

        $this->invokeHandler($httpClient, new DeliverWebhookMessage($delivery->getId()->toRfc4122()));

        $this->em->clear();
        $refreshed = $this->em->find(WebhookDelivery::class, $delivery->getId());
        $this->assertNotNull($refreshed);
        $this->assertSame(WebhookDeliveryStatus::Failed, $refreshed->getStatus());
        $this->assertNull($refreshed->getLastResponseCode());
        $this->assertStringContainsString('Connection refused', (string) $refreshed->getLastResponseBody());
    }

    #[Test]
    public function inactiveEndpointIsSkipped(): void
    {
        [$endpoint] = $this->createEndpoint();
        $endpoint->setIsActive(false);
        $this->em->flush();
        $delivery = $this->createDelivery($endpoint);

        $called = false;
        $httpClient = new MockHttpClient(static function () use (&$called) {
            $called = true;

            return new MockResponse('{}', ['http_code' => 200]);
        });

        $this->invokeHandler($httpClient, new DeliverWebhookMessage($delivery->getId()->toRfc4122()));

        $this->assertFalse($called, 'HTTP client should not be called for an inactive endpoint');

        $this->em->clear();
        $refreshed = $this->em->find(WebhookDelivery::class, $delivery->getId());
        $this->assertNotNull($refreshed);
        $this->assertSame(WebhookDeliveryStatus::Pending, $refreshed->getStatus());
    }

    #[Test]
    public function unknownDeliveryIdIsANoOp(): void
    {
        $httpClient = new MockHttpClient(function (): void {
            $this->fail('HTTP client should not be called for an unknown delivery');
        });

        $this->invokeHandler($httpClient, new DeliverWebhookMessage('019836b1-0000-7000-8000-000000000000'));

        $this->addToAssertionCount(1); // reaching here without an exception is the assertion
    }

    /** @return array{0: WebhookEndpoint, 1: string} entity, plaintext secret */
    private function createEndpoint(): array
    {
        $user = $this->createUserWithOrg('webhook-handler-' . bin2hex(random_bytes(4)) . '@example.com');
        $org = $user->getOrganization();
        $this->assertNotNull($org);

        $endpointService = static::getContainer()->get(WebhookEndpointService::class);
        [$endpoint, $secret] = $endpointService->createEndpoint($org, 'https://example.com/hook', ['org.member.invited']);

        return [$endpoint, $secret];
    }

    private function createDelivery(WebhookEndpoint $endpoint): WebhookDelivery
    {
        $delivery = new WebhookDelivery($endpoint, 'org.member.invited', ['foo' => 'bar']);
        $this->em->persist($delivery);
        $this->em->flush();

        return $delivery;
    }

    private function invokeHandler(MockHttpClient $httpClient, DeliverWebhookMessage $message): void
    {
        $handler = new DeliverWebhookMessageHandler(
            static::getContainer()->get(WebhookDeliveryRepository::class),
            static::getContainer()->get(WebhookEndpointService::class),
            new WebhookSigner(),
            $httpClient,
            new NullLogger(),
        );

        $handler($message);
    }
}
