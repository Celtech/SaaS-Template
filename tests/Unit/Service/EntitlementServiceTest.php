<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Entitlement;
use App\Entity\EntitlementType;
use App\Entity\Organization;
use App\Entity\Plan;
use App\Entity\PlanEntitlement;
use App\Entity\Subscription;
use App\Entity\SubscriptionStatus;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Service\EntitlementService;
use App\Tests\UnitTestCase;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class EntitlementServiceTest extends UnitTestCase
{
    /** @var SubscriptionRepository&Stub */
    private SubscriptionRepository $subscriptionRepository;
    private ArrayAdapter $cache;
    private EntitlementService $service;
    private Organization $org;

    protected function setUp(): void
    {
        $this->subscriptionRepository = $this->createStub(SubscriptionRepository::class);
        $this->cache = new ArrayAdapter();
        $this->service = new EntitlementService($this->subscriptionRepository, $this->cache);
        $this->org = new Organization('Acme', new User('owner@example.com', 'Owner'));
    }

    // -------------------------------------------------------------------------
    // isOrgAccessible
    // -------------------------------------------------------------------------

    #[Test]
    public function isOrgAccessibleReturnsFalseWhenNoSubscription(): void
    {
        $this->subscriptionRepository->method('findForOrg')->willReturn(null);

        $this->assertFalse($this->service->isOrgAccessible($this->org));
    }

    #[Test]
    public function isOrgAccessibleReturnsTrueForActiveSubscription(): void
    {
        $this->subscriptionRepository->method('findForOrg')
            ->willReturn($this->makeSubscription(SubscriptionStatus::Active));

        $this->assertTrue($this->service->isOrgAccessible($this->org));
    }

    #[Test]
    public function isOrgAccessibleReturnsFalseForCanceledSubscription(): void
    {
        $this->subscriptionRepository->method('findForOrg')
            ->willReturn($this->makeSubscription(SubscriptionStatus::Canceled));

        $this->assertFalse($this->service->isOrgAccessible($this->org));
    }

    // -------------------------------------------------------------------------
    // isEnabled
    // -------------------------------------------------------------------------

    #[Test]
    public function isEnabledReturnsTrueForEnabledBooleanEntitlement(): void
    {
        $this->subscriptionRepository->method('findForOrg')
            ->willReturn($this->makeSubscriptionWithEntitlement('can_export', EntitlementType::Boolean, '1'));

        $this->assertTrue($this->service->isEnabled($this->org, 'can_export'));
    }

    #[Test]
    public function isEnabledReturnsFalseForDisabledBooleanEntitlement(): void
    {
        $this->subscriptionRepository->method('findForOrg')
            ->willReturn($this->makeSubscriptionWithEntitlement('can_export', EntitlementType::Boolean, '0'));

        $this->assertFalse($this->service->isEnabled($this->org, 'can_export'));
    }

    #[Test]
    public function isEnabledReturnsTrueForNonZeroIntegerEntitlement(): void
    {
        $this->subscriptionRepository->method('findForOrg')
            ->willReturn($this->makeSubscriptionWithEntitlement('max_seats', EntitlementType::Integer, '5'));

        $this->assertTrue($this->service->isEnabled($this->org, 'max_seats'));
    }

    #[Test]
    public function isEnabledReturnsFalseForZeroIntegerEntitlement(): void
    {
        $this->subscriptionRepository->method('findForOrg')
            ->willReturn($this->makeSubscriptionWithEntitlement('max_seats', EntitlementType::Integer, '0'));

        $this->assertFalse($this->service->isEnabled($this->org, 'max_seats'));
    }

    #[Test]
    public function isEnabledReturnsTrueForUnlimitedEntitlement(): void
    {
        $this->subscriptionRepository->method('findForOrg')
            ->willReturn($this->makeSubscriptionWithEntitlement('max_api_calls', EntitlementType::Unlimited, '-1'));

        $this->assertTrue($this->service->isEnabled($this->org, 'max_api_calls'));
    }

    #[Test]
    public function isEnabledReturnsFalseWhenEntitlementNotOnPlan(): void
    {
        $this->subscriptionRepository->method('findForOrg')
            ->willReturn($this->makeSubscription(SubscriptionStatus::Active));

        $this->assertFalse($this->service->isEnabled($this->org, 'nonexistent_slug'));
    }

    #[Test]
    public function isEnabledReturnsFalseWhenSubscriptionNotAccessible(): void
    {
        $this->subscriptionRepository->method('findForOrg')
            ->willReturn($this->makeSubscription(SubscriptionStatus::Canceled));

        $this->assertFalse($this->service->isEnabled($this->org, 'can_export'));
    }

    // -------------------------------------------------------------------------
    // limit
    // -------------------------------------------------------------------------

    #[Test]
    public function limitReturnsMinusOneForUnlimitedEntitlement(): void
    {
        $this->subscriptionRepository->method('findForOrg')
            ->willReturn($this->makeSubscriptionWithEntitlement('max_seats', EntitlementType::Unlimited, '-1'));

        $this->assertSame(-1, $this->service->limit($this->org, 'max_seats'));
    }

    #[Test]
    public function limitReturnsIntegerValueForIntegerEntitlement(): void
    {
        $this->subscriptionRepository->method('findForOrg')
            ->willReturn($this->makeSubscriptionWithEntitlement('max_seats', EntitlementType::Integer, '10'));

        $this->assertSame(10, $this->service->limit($this->org, 'max_seats'));
    }

    #[Test]
    public function limitReturnsZeroWhenEntitlementNotOnPlan(): void
    {
        $this->subscriptionRepository->method('findForOrg')
            ->willReturn($this->makeSubscription(SubscriptionStatus::Active));

        $this->assertSame(0, $this->service->limit($this->org, 'nonexistent'));
    }

    #[Test]
    public function limitReturnsZeroWhenSubscriptionNotAccessible(): void
    {
        $this->subscriptionRepository->method('findForOrg')
            ->willReturn($this->makeSubscription(SubscriptionStatus::PastDue));

        $this->assertSame(0, $this->service->limit($this->org, 'max_seats'));
    }

    // -------------------------------------------------------------------------
    // Caching
    // -------------------------------------------------------------------------

    #[Test]
    public function repositoryIsQueriedOnlyOnceForMultipleLookups(): void
    {
        /** @var SubscriptionRepository&MockObject $repo */
        $repo = $this->createMock(SubscriptionRepository::class);
        $repo->expects($this->once())
            ->method('findForOrg')
            ->willReturn($this->makeSubscriptionWithEntitlement('can_export', EntitlementType::Boolean, '1'));

        $service = new EntitlementService($repo, $this->cache);

        $service->isOrgAccessible($this->org);
        $service->isEnabled($this->org, 'can_export');
        $service->limit($this->org, 'can_export');
    }

    #[Test]
    public function invalidateForOrgCausesRepositoryToBeQueriedAgain(): void
    {
        /** @var SubscriptionRepository&MockObject $repo */
        $repo = $this->createMock(SubscriptionRepository::class);
        $repo->expects($this->exactly(2))
            ->method('findForOrg')
            ->willReturn($this->makeSubscription(SubscriptionStatus::Active));

        $service = new EntitlementService($repo, $this->cache);

        $service->isOrgAccessible($this->org);
        $service->invalidateForOrg($this->org);
        $service->isOrgAccessible($this->org);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeSubscription(SubscriptionStatus $status): Subscription
    {
        /** @var Plan&MockObject $plan */
        $plan = $this->createMock(Plan::class);
        $plan->method('getPlanEntitlements')->willReturn(new ArrayCollection());

        /** @var Subscription&MockObject $sub */
        $sub = $this->createMock(Subscription::class);
        $sub->method('isAccessible')->willReturn($status->isAccessible());
        $sub->method('getPlan')->willReturn($plan);

        return $sub;
    }

    private function makeSubscriptionWithEntitlement(
        string $slug,
        EntitlementType $type,
        string $value,
    ): Subscription {
        $entitlement = new Entitlement($slug, ucfirst(str_replace('_', ' ', $slug)), $type);

        /** @var Plan&MockObject $plan */
        $plan = $this->createMock(Plan::class);
        $pe = new PlanEntitlement($plan, $entitlement, $value);
        $plan->method('getPlanEntitlements')->willReturn(new ArrayCollection([$pe]));

        /** @var Subscription&MockObject $sub */
        $sub = $this->createMock(Subscription::class);
        $sub->method('isAccessible')->willReturn(true);
        $sub->method('getPlan')->willReturn($plan);

        return $sub;
    }
}
