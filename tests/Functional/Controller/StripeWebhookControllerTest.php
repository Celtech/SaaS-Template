<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

final class StripeWebhookControllerTest extends FunctionalTestCase
{
    private string $webhookSecret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->webhookSecret = $_SERVER['STRIPE_WEBHOOK_SECRET'] ?? '';
    }

    // -------------------------------------------------------------------------
    // Signature validation
    // -------------------------------------------------------------------------

    #[Test]
    public function invalidSignatureReturns400(): void
    {
        $this->client->request(
            'POST',
            '/stripe/webhook',
            [],
            [],
            ['HTTP_Stripe-Signature' => 't=1234567890,v1=invalidsignature', 'CONTENT_TYPE' => 'application/json'],
            '{"id":"evt_test","type":"customer.subscription.updated","data":{"object":{}}}'
        );

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('Invalid signature', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function invalidJsonPayloadReturns400(): void
    {
        $payload = 'this is not json';
        $this->client->request(
            'POST',
            '/stripe/webhook',
            [],
            [],
            ['HTTP_Stripe-Signature' => $this->sign($payload), 'CONTENT_TYPE' => 'application/json'],
            $payload
        );

        $this->assertResponseStatusCodeSame(400);
    }

    // -------------------------------------------------------------------------
    // Unsupported event type
    // -------------------------------------------------------------------------

    #[Test]
    public function unsupportedEventTypeReturns200(): void
    {
        $payload = $this->buildEventPayload('payment_intent.created', ['object' => 'payment_intent', 'id' => 'pi_test']);

        $this->client->request(
            'POST',
            '/stripe/webhook',
            [],
            [],
            ['HTTP_Stripe-Signature' => $this->sign($payload), 'CONTENT_TYPE' => 'application/json'],
            $payload
        );

        $this->assertResponseIsSuccessful();
        $this->assertSame('OK', (string) $this->client->getResponse()->getContent());
    }

    // -------------------------------------------------------------------------
    // customer.subscription.updated
    // -------------------------------------------------------------------------

    #[Test]
    public function subscriptionUpdatedWithUnknownStripeIdReturns200(): void
    {
        $payload = $this->buildEventPayload('customer.subscription.updated', $this->subscriptionObject('sub_unknown_xyz'));

        $this->client->request(
            'POST',
            '/stripe/webhook',
            [],
            [],
            ['HTTP_Stripe-Signature' => $this->sign($payload), 'CONTENT_TYPE' => 'application/json'],
            $payload
        );

        $this->assertResponseIsSuccessful();
    }

    // -------------------------------------------------------------------------
    // customer.subscription.deleted
    // -------------------------------------------------------------------------

    #[Test]
    public function subscriptionDeletedWithUnknownStripeIdReturns200(): void
    {
        $payload = $this->buildEventPayload('customer.subscription.deleted', $this->subscriptionObject('sub_unknown_xyz'));

        $this->client->request(
            'POST',
            '/stripe/webhook',
            [],
            [],
            ['HTTP_Stripe-Signature' => $this->sign($payload), 'CONTENT_TYPE' => 'application/json'],
            $payload
        );

        $this->assertResponseIsSuccessful();
    }

    // -------------------------------------------------------------------------
    // invoice.payment_succeeded
    // -------------------------------------------------------------------------

    #[Test]
    public function invoicePaymentSucceededWithNoMatchingSubscriptionReturns200(): void
    {
        $payload = $this->buildEventPayload(
            'invoice.payment_succeeded',
            $this->invoiceObject('in_test_success', 'sub_no_match', 9900)
        );

        $this->client->request(
            'POST',
            '/stripe/webhook',
            [],
            [],
            ['HTTP_Stripe-Signature' => $this->sign($payload), 'CONTENT_TYPE' => 'application/json'],
            $payload
        );

        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function invoicePaymentSucceededWithNoSubscriptionIdReturns200(): void
    {
        $invoiceData = [
            'id' => 'in_test_no_sub',
            'object' => 'invoice',
            'amount_paid' => 0,
            'currency' => 'usd',
            'parent' => null,
        ];
        $payload = $this->buildEventPayload('invoice.payment_succeeded', $invoiceData);

        $this->client->request(
            'POST',
            '/stripe/webhook',
            [],
            [],
            ['HTTP_Stripe-Signature' => $this->sign($payload), 'CONTENT_TYPE' => 'application/json'],
            $payload
        );

        $this->assertResponseIsSuccessful();
    }

    // -------------------------------------------------------------------------
    // invoice.payment_failed
    // -------------------------------------------------------------------------

    #[Test]
    public function invoicePaymentFailedWithNoMatchingSubscriptionReturns200(): void
    {
        $payload = $this->buildEventPayload(
            'invoice.payment_failed',
            $this->invoiceObject('in_test_fail', 'sub_no_match', 9900, attempt_count: 1)
        );

        $this->client->request(
            'POST',
            '/stripe/webhook',
            [],
            [],
            ['HTTP_Stripe-Signature' => $this->sign($payload), 'CONTENT_TYPE' => 'application/json'],
            $payload
        );

        $this->assertResponseIsSuccessful();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function sign(string $payload): string
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->webhookSecret);

        return "t={$timestamp},v1={$signature}";
    }

    private function buildEventPayload(string $type, array $dataObject): string
    {
        return json_encode([
            'id' => 'evt_test_' . substr(md5($type), 0, 8),
            'object' => 'event',
            'type' => $type,
            'created' => time(),
            'data' => [
                'object' => $dataObject,
            ],
        ], \JSON_THROW_ON_ERROR);
    }

    private function subscriptionObject(string $stripeSubscriptionId, string $status = 'active'): array
    {
        return [
            'id' => $stripeSubscriptionId,
            'object' => 'subscription',
            'status' => $status,
            'customer' => 'cus_test_123',
            'metadata' => [],
            'items' => ['object' => 'list', 'data' => []],
            'latest_invoice' => null,
        ];
    }

    private function invoiceObject(
        string $invoiceId,
        string $subscriptionId,
        int $amountCents,
        int $attempt_count = 0,
    ): array {
        return [
            'id' => $invoiceId,
            'object' => 'invoice',
            'amount_paid' => $amountCents,
            'amount_due' => $amountCents,
            'currency' => 'usd',
            'attempt_count' => $attempt_count,
            'parent' => [
                'type' => 'subscription_details',
                'subscription_details' => [
                    'subscription' => $subscriptionId,
                ],
            ],
        ];
    }
}
