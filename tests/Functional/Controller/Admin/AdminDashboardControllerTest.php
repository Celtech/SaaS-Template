<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\Plan;
use App\Entity\Subscription;
use App\Entity\SubscriptionStatus;
use App\Entity\User;
use App\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

final class AdminDashboardControllerTest extends FunctionalTestCase
{
    #[Test]
    public function indexRequiresSuperAdmin(): void
    {
        $user = $this->createUserWithOrg('dashboard-regular@example.com');
        $this->client->loginUser($user);

        $this->client->request('GET', '/admin');

        $this->assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function indexShowsRealStatCounts(): void
    {
        // createSuperAdmin already creates one organization (the admin's own).
        $admin = $this->createSuperAdmin('dashboard-admin@example.com');
        $ownerA = $this->createUserWithOrg('dashboard-org-a@example.com', orgName: 'Org A');
        $ownerB = $this->createUserWithOrg('dashboard-org-b@example.com', orgName: 'Org B');

        $this->createSubscription($ownerA, SubscriptionStatus::Active);
        $this->createSubscription($ownerB, SubscriptionStatus::Trialing);

        $this->loginAsSuperAdminWithStepUpConfirmed($admin);
        $this->client->request('GET', '/admin');

        $this->assertResponseIsSuccessful();
        // 3 users (admin + 2 owners), 3 orgs, 1 active subscription (the trialing one doesn't count).
        $this->assertSelectorTextSame('#stat-user-count', '3');
        $this->assertSelectorTextSame('#stat-organization-count', '3');
        $this->assertSelectorTextSame('#stat-active-subscription-count', '1');
    }

    private function createSubscription(User $owner, SubscriptionStatus $status): void
    {
        $org = $owner->getOrganization();
        $this->assertNotNull($org);

        $plan = new Plan('plan-' . $owner->getId()->toRfc4122(), 'Test Plan');
        $this->em->persist($plan);

        $subscription = new Subscription($org, $plan, $status);
        $this->em->persist($subscription);
        $this->em->flush();
    }
}
