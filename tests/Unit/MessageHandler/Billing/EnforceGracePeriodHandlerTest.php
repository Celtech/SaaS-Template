<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler\Billing;

use App\Entity\BillingSettings;
use App\Entity\Organization;
use App\Entity\Plan;
use App\Entity\Subscription;
use App\Entity\SubscriptionStatus;
use App\Entity\User;
use App\Message\Billing\EnforceGracePeriodMessage;
use App\MessageHandler\Billing\EnforceGracePeriodHandler;
use App\Repository\BillingSettingsRepository;
use App\Repository\SubscriptionRepository;
use App\Service\Audit\AuditLogger;
use App\Service\EntitlementService;
use App\Tests\UnitTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\NullLogger;

final class EnforceGracePeriodHandlerTest extends UnitTestCase
{
    /** @var SubscriptionRepository&Stub */
    private SubscriptionRepository $subscriptionRepository;
    /** @var BillingSettingsRepository&Stub */
    private BillingSettingsRepository $billingSettingsRepository;
    /** @var EntityManagerInterface&MockObject */
    private EntityManagerInterface $em;
    /** @var AuditLogger&Stub */
    private AuditLogger $auditLogger;
    /** @var EntitlementService&MockObject */
    private EntitlementService $entitlementService;
    private BillingSettings $settings;

    protected function setUp(): void
    {
        $this->subscriptionRepository = $this->createStub(SubscriptionRepository::class);
        $this->billingSettingsRepository = $this->createStub(BillingSettingsRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->auditLogger = $this->createStub(AuditLogger::class);
        $this->entitlementService = $this->createMock(EntitlementService::class);
        $this->settings = new BillingSettings();
        $this->billingSettingsRepository->method('getSettings')->willReturn($this->settings);
    }

    #[Test]
    public function doesNothingWhenNoPastGracePeriodSubscriptions(): void
    {
        $this->subscriptionRepository->method('findPastGracePeriod')->willReturn([]);
        $this->em->expects($this->never())->method('flush');

        $this->makeHandler()(new EnforceGracePeriodMessage());
    }

    #[Test]
    public function cancelsPastDueSubscriptionAfterGracePeriod(): void
    {
        $sub = $this->makeSubscription(SubscriptionStatus::PastDue);
        $this->subscriptionRepository->method('findPastGracePeriod')->willReturn([$sub]);

        $this->makeHandler()(new EnforceGracePeriodMessage());

        $this->assertSame(SubscriptionStatus::Canceled, $sub->getStatus());
    }

    #[Test]
    public function cancelsMultipleSubscriptions(): void
    {
        $subs = [
            $this->makeSubscription(SubscriptionStatus::PastDue),
            $this->makeSubscription(SubscriptionStatus::PastDue),
        ];
        $this->subscriptionRepository->method('findPastGracePeriod')->willReturn($subs);
        $this->em->expects($this->once())->method('flush');

        $this->makeHandler()(new EnforceGracePeriodMessage());

        foreach ($subs as $sub) {
            $this->assertSame(SubscriptionStatus::Canceled, $sub->getStatus());
        }
    }

    #[Test]
    public function passesGracePeriodDaysFromSettingsToRepository(): void
    {
        $this->settings->setGracePeriodDays(7);

        /** @var SubscriptionRepository&MockObject $repo */
        $repo = $this->createMock(SubscriptionRepository::class);
        $repo->expects($this->once())
            ->method('findPastGracePeriod')
            ->with(7)
            ->willReturn([]);

        (new EnforceGracePeriodHandler(
            $repo,
            $this->billingSettingsRepository,
            $this->em,
            $this->auditLogger,
            $this->entitlementService,
            new NullLogger(),
        ))(new EnforceGracePeriodMessage());
    }

    #[Test]
    public function entitlementCacheIsInvalidatedAfterFlush(): void
    {
        $sub = $this->makeSubscription(SubscriptionStatus::PastDue);
        $this->subscriptionRepository->method('findPastGracePeriod')->willReturn([$sub]);

        $entitlementService = $this->createMock(EntitlementService::class);
        $entitlementService->expects($this->once())->method('invalidateForOrg');

        $this->makeHandler($entitlementService)(new EnforceGracePeriodMessage());
    }

    private function makeHandler(?EntitlementService $entitlementService = null): EnforceGracePeriodHandler
    {
        return new EnforceGracePeriodHandler(
            $this->subscriptionRepository,
            $this->billingSettingsRepository,
            $this->em,
            $this->auditLogger,
            $entitlementService ?? $this->entitlementService,
            new NullLogger(),
        );
    }

    private function makeSubscription(SubscriptionStatus $status): Subscription
    {
        $org = new Organization('Acme', new User('owner@example.com', 'Owner'));
        $plan = new Plan('pro', 'Pro');

        return new Subscription($org, $plan, $status);
    }
}
