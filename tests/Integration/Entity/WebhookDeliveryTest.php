<?php

declare(strict_types=1);

namespace App\Tests\Integration\Entity;

use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\Enum\WebhookDeliveryStatus;
use App\Tests\FunctionalTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;

final class WebhookDeliveryTest extends FunctionalTestCase
{
    #[Test]
    public function recordSuccessSetsSuccessStatusAndClearsNextAttempt(): void
    {
        $delivery = $this->createDelivery();

        $delivery->recordFailure(500, 'error'); // schedule a retry first
        $delivery->recordSuccess(200, 'ok');

        $this->assertSame(WebhookDeliveryStatus::Success, $delivery->getStatus());
        $this->assertSame(2, $delivery->getAttempts());
        $this->assertSame(200, $delivery->getLastResponseCode());
        $this->assertNull($delivery->getNextAttemptAt());
    }

    #[Test]
    public function recordFailureFollowsTheDocumentedBackoffSchedule(): void
    {
        $delivery = $this->createDelivery();
        $expectedDelays = WebhookDelivery::RETRY_BACKOFF_SECONDS; // [60, 300, 1800, 7200, 43200]

        foreach ($expectedDelays as $i => $expectedDelay) {
            $before = new DateTimeImmutable();
            $delivery->recordFailure(500, 'error');

            $this->assertSame($i + 1, $delivery->getAttempts());

            if ($i + 1 >= WebhookDelivery::MAX_ATTEMPTS) {
                $this->assertSame(WebhookDeliveryStatus::Exhausted, $delivery->getStatus());
                $this->assertNull($delivery->getNextAttemptAt());

                break;
            }

            $this->assertSame(WebhookDeliveryStatus::Failed, $delivery->getStatus());
            $nextAttempt = $delivery->getNextAttemptAt();
            $this->assertNotNull($nextAttempt);
            $actualDelay = $nextAttempt->getTimestamp() - $before->getTimestamp();
            $this->assertEqualsWithDelta($expectedDelay, $actualDelay, 2);
        }
    }

    #[Test]
    public function deliveryIsExhaustedAfterMaxAttempts(): void
    {
        $delivery = $this->createDelivery();

        for ($i = 0; $i < WebhookDelivery::MAX_ATTEMPTS; ++$i) {
            $delivery->recordFailure(500, 'error');
        }

        $this->assertSame(WebhookDeliveryStatus::Exhausted, $delivery->getStatus());
        $this->assertSame(WebhookDelivery::MAX_ATTEMPTS, $delivery->getAttempts());
    }

    #[Test]
    public function isDueIsFalseUntilTheBackoffElapses(): void
    {
        $delivery = $this->createDelivery();
        $delivery->recordFailure(500, 'error');

        $this->assertFalse($delivery->isDue(), 'A freshly-scheduled retry (60s out) should not be due yet');
    }

    #[Test]
    public function isDueIsFalseForPendingAndSuccessStatuses(): void
    {
        $pending = $this->createDelivery();
        $this->assertFalse($pending->isDue());

        $succeeded = $this->createDelivery();
        $succeeded->recordSuccess(200, 'ok');
        $this->assertFalse($succeeded->isDue());
    }

    #[Test]
    public function responseBodyIsTruncatedAtMaxLength(): void
    {
        $delivery = $this->createDelivery();
        $hugeBody = str_repeat('x', WebhookDelivery::RESPONSE_BODY_MAX_LENGTH + 500);

        $delivery->recordSuccess(200, $hugeBody);

        $this->assertSame(WebhookDelivery::RESPONSE_BODY_MAX_LENGTH, \strlen((string) $delivery->getLastResponseBody()));
    }

    private function createDelivery(): WebhookDelivery
    {
        $user = $this->createUserWithOrg('webhook-delivery-test-' . bin2hex(random_bytes(4)) . '@example.com');
        $org = $user->getOrganization();
        $this->assertNotNull($org);

        $endpoint = new WebhookEndpoint($org, 'https://example.com/hook', 'ciphertext', 'abcd', ['org.member.invited']);
        $this->em->persist($endpoint);

        $delivery = new WebhookDelivery($endpoint, 'org.member.invited', ['foo' => 'bar']);
        $this->em->persist($delivery);
        $this->em->flush();

        return $delivery;
    }
}
