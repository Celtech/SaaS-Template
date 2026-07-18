<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\User;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

final class WebhookAdminControllerTest extends FunctionalTestCase
{
    #[Test]
    public function indexRequiresSuperAdmin(): void
    {
        $user = $this->createUserWithOrg('webhook-admin-regular@example.com');
        $this->client->loginUser($user);

        $this->client->request('GET', '/admin/webhooks');

        $this->assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function indexListsDeliveriesAcrossOrganizations(): void
    {
        $admin = $this->createSuperAdmin('webhook-admin-index@example.com');
        $ownerA = $this->createUserWithOrg('webhook-admin-org-a@example.com', orgName: 'Org A');
        $ownerB = $this->createUserWithOrg('webhook-admin-org-b@example.com', orgName: 'Org B');

        $this->createDelivery($ownerA, 'https://a.example.com/hook', 'org.member.invited');
        $this->createDelivery($ownerB, 'https://b.example.com/hook', 'billing.subscription.created');

        $this->loginAsSuperAdminWithStepUpConfirmed($admin);
        $this->client->request('GET', '/admin/webhooks');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Org A');
        $this->assertSelectorTextContains('body', 'Org B');
        $this->assertSelectorTextContains('body', 'org.member.invited');
        $this->assertSelectorTextContains('body', 'billing.subscription.created');
    }

    #[Test]
    public function filtersByEventPrefix(): void
    {
        $admin = $this->createSuperAdmin('webhook-admin-filter-event@example.com');
        $owner = $this->createUserWithOrg('webhook-admin-filter-owner@example.com');

        $this->createDelivery($owner, 'https://a.example.com/hook', 'org.member.invited');
        $this->createDelivery($owner, 'https://a.example.com/hook', 'billing.subscription.created');

        $this->loginAsSuperAdminWithStepUpConfirmed($admin);
        $this->client->request('GET', '/admin/webhooks', ['event' => 'org.']);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'org.member.invited');
        $this->assertSelectorTextNotContains('body', 'billing.subscription.created');
    }

    #[Test]
    public function filtersByStatus(): void
    {
        $admin = $this->createSuperAdmin('webhook-admin-filter-status@example.com');
        $owner = $this->createUserWithOrg('webhook-admin-filter-status-owner@example.com');

        $pending = $this->createDelivery($owner, 'https://a.example.com/hook', 'org.member.invited');
        $succeeded = $this->createDelivery($owner, 'https://a.example.com/hook', 'org.member.joined');
        $succeeded->recordSuccess(200, 'ok');
        $this->em->flush();

        $this->loginAsSuperAdminWithStepUpConfirmed($admin);
        $this->client->request('GET', '/admin/webhooks', ['status' => 'success']);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'org.member.joined');
        $this->assertSelectorTextNotContains('body', 'org.member.invited');
    }

    private function createDelivery(User $owner, string $url, string $eventType): WebhookDelivery
    {
        $org = $owner->getOrganization();
        $this->assertNotNull($org);

        $endpoint = new WebhookEndpoint($org, $url, 'ciphertext', 'abcd', [$eventType]);
        $this->em->persist($endpoint);

        $delivery = new WebhookDelivery($endpoint, $eventType, []);
        $this->em->persist($delivery);
        $this->em->flush();

        return $delivery;
    }
}
