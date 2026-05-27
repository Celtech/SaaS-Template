<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler\Billing;

use App\Entity\BillingSettings;
use App\Entity\Organization;
use App\Entity\Plan;
use App\Entity\Subscription;
use App\Entity\SubscriptionStatus;
use App\Entity\TrialExpiryBehavior;
use App\Entity\User;
use App\Message\Billing\ProcessExpiredTrialsMessage;
use App\MessageHandler\Billing\ProcessExpiredTrialsHandler;
use App\Repository\BillingSettingsRepository;
use App\Repository\PlanRepository;
use App\Repository\SubscriptionRepository;
use App\Service\Audit\AuditLogger;
use App\Service\EntitlementService;
use App\Tests\UnitTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\NullLogger;

final class ProcessExpiredTrialsHandlerTest extends UnitTestCase
{
    /** @var SubscriptionRepository&Stub */
    private SubscriptionRepository $subscriptionRepository;
    /** @var BillingSettingsRepository&Stub */
    private BillingSettingsRepository $billingSettingsRepository;
    /** @var PlanRepository&Stub */
    private PlanRepository $planRepository;
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
        $this->planRepository = $this->createStub(PlanRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->auditLogger = $this->createStub(AuditLogger::class);
        $this->entitlementService = $this->createMock(EntitlementService::class);
        $this->settings = new BillingSettings();
        $this->billingSettingsRepository->method('getSettings')->willReturn($this->settings);
    }

    #[Test]
    public function doesNothingWhenNoExpiredTrials(): void
    {
        $this->subscriptionRepository->method('findExpiredTrials')->willReturn([]);
        $this->em->expects($this->never())->method('flush');

        $this->makeHandler()(new ProcessExpiredTrialsMessage());
    }

    #[Test]
    public function requirePaymentSetsStatusToUnpaid(): void
    {
        $this->settings->setTrialExpiryBehavior(TrialExpiryBehavior::RequirePayment);
        $sub = $this->makeSubscription();
        $this->subscriptionRepository->method('findExpiredTrials')->willReturn([$sub]);
        $this->em->expects($this->once())->method('flush');

        $this->makeHandler()(new ProcessExpiredTrialsMessage());

        $this->assertSame(SubscriptionStatus::Unpaid, $sub->getStatus());
    }

    #[Test]
    public function cancelBehaviorSetsStatusToCanceled(): void
    {
        $this->settings->setTrialExpiryBehavior(TrialExpiryBehavior::Cancel);
        $sub = $this->makeSubscription();
        $this->subscriptionRepository->method('findExpiredTrials')->willReturn([$sub]);

        $this->makeHandler()(new ProcessExpiredTrialsMessage());

        $this->assertSame(SubscriptionStatus::Canceled, $sub->getStatus());
    }

    #[Test]
    public function downgradeToFreeSwitchesPlanAndSetsActive(): void
    {
        $this->settings->setTrialExpiryBehavior(TrialExpiryBehavior::DowngradeToFree);
        $freePlan = new Plan('free', 'Free');
        $this->planRepository->method('findFreePlan')->willReturn($freePlan);

        $sub = $this->makeSubscription();
        $this->subscriptionRepository->method('findExpiredTrials')->willReturn([$sub]);

        $this->makeHandler()(new ProcessExpiredTrialsMessage());

        $this->assertSame(SubscriptionStatus::Active, $sub->getStatus());
        $this->assertSame($freePlan, $sub->getPlan());
    }

    #[Test]
    public function downgradeToFreeFallsBackToRequirePaymentWhenNoFreePlan(): void
    {
        $this->settings->setTrialExpiryBehavior(TrialExpiryBehavior::DowngradeToFree);
        $this->planRepository->method('findFreePlan')->willReturn(null);

        $sub = $this->makeSubscription();
        $this->subscriptionRepository->method('findExpiredTrials')->willReturn([$sub]);

        $this->makeHandler()(new ProcessExpiredTrialsMessage());

        $this->assertSame(SubscriptionStatus::Unpaid, $sub->getStatus());
    }

    #[Test]
    public function entitlementCacheIsInvalidatedAfterFlush(): void
    {
        $this->settings->setTrialExpiryBehavior(TrialExpiryBehavior::Cancel);
        $sub = $this->makeSubscription();
        $this->subscriptionRepository->method('findExpiredTrials')->willReturn([$sub]);

        $entitlementService = $this->createMock(EntitlementService::class);
        $entitlementService->expects($this->once())->method('invalidateForOrg');

        $this->makeHandler($entitlementService)(new ProcessExpiredTrialsMessage());
    }

    #[Test]
    public function flushIsCalledOnceForMultipleExpiredTrials(): void
    {
        $this->settings->setTrialExpiryBehavior(TrialExpiryBehavior::Cancel);
        $subs = [$this->makeSubscription(), $this->makeSubscription()];
        $this->subscriptionRepository->method('findExpiredTrials')->willReturn($subs);
        $this->em->expects($this->once())->method('flush');

        $this->makeHandler()(new ProcessExpiredTrialsMessage());
    }

    private function makeHandler(?EntitlementService $entitlementService = null): ProcessExpiredTrialsHandler
    {
        return new ProcessExpiredTrialsHandler(
            $this->subscriptionRepository,
            $this->billingSettingsRepository,
            $this->planRepository,
            $this->em,
            $this->auditLogger,
            $entitlementService ?? $this->entitlementService,
            new NullLogger(),
        );
    }

    private function makeSubscription(): Subscription
    {
        $org = new Organization('Acme', new User('owner@example.com', 'Owner'));
        $plan = new Plan('pro', 'Pro');

        return new Subscription($org, $plan, SubscriptionStatus::Trialing);
    }
}
