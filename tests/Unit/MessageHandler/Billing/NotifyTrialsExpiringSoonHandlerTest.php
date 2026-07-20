<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler\Billing;

use App\Entity\Organization;
use App\Entity\Permission;
use App\Entity\Plan;
use App\Entity\Subscription;
use App\Entity\SubscriptionStatus;
use App\Entity\User;
use App\Message\Billing\NotifyTrialsExpiringSoonMessage;
use App\Message\Notification\SendNotificationMessage;
use App\MessageHandler\Billing\NotifyTrialsExpiringSoonHandler;
use App\Repository\NotificationPreferenceRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRoleRepository;
use App\Service\Notification\NotificationDispatcher;
use App\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class NotifyTrialsExpiringSoonHandlerTest extends UnitTestCase
{
    private SubscriptionRepository&MockObject $subscriptions;
    private UserRoleRepository&MockObject $userRoles;
    private MessageBusInterface&MockObject $bus;
    private NotifyTrialsExpiringSoonHandler $handler;

    protected function setUp(): void
    {
        $this->subscriptions = $this->createMock(SubscriptionRepository::class);
        $this->userRoles = $this->createMock(UserRoleRepository::class);
        $this->bus = $this->createMock(MessageBusInterface::class);

        $preferences = $this->createMock(NotificationPreferenceRepository::class);
        $preferences->method('findOneForUserTypeChannel')->willReturn(null);
        $notifications = new NotificationDispatcher($preferences, $this->bus);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://app.example.com/org/settings/billing');

        $this->handler = new NotifyTrialsExpiringSoonHandler(
            $this->subscriptions,
            $this->userRoles,
            $notifications,
            $urlGenerator,
        );
    }

    #[Test]
    public function doesNothingWhenNoTrialsAreEndingSoon(): void
    {
        $this->subscriptions->expects($this->once())->method('findTrialsEndingBetween')->with(3, 4)->willReturn([]);
        $this->bus->expects($this->never())->method('dispatch');

        ($this->handler)(new NotifyTrialsExpiringSoonMessage());
    }

    #[Test]
    public function notifiesEveryBillingAdminForEachExpiringTrial(): void
    {
        $owner = new User('owner@example.com', 'Owner');
        $org = new Organization('Acme', $owner);
        $plan = new Plan('pro', 'Pro');
        $subscription = new Subscription($org, $plan, SubscriptionStatus::Trialing);

        $this->subscriptions->method('findTrialsEndingBetween')->willReturn([$subscription]);
        $this->userRoles->expects($this->once())
            ->method('findUsersWithPermissionInOrg')
            ->with($org->getId(), Permission::OrgBillingManage)
            ->willReturn([$owner]);

        $dispatched = [];
        $this->bus->expects($this->exactly(2)) // billing.trial_expiring defaults to in_app + email
            ->method('dispatch')
            ->willReturnCallback(static function (object $message) use (&$dispatched): Envelope {
                $dispatched[] = $message;

                return new Envelope($message);
            });

        ($this->handler)(new NotifyTrialsExpiringSoonMessage());

        $this->assertCount(2, $dispatched);
        $this->assertContainsOnlyInstancesOf(SendNotificationMessage::class, $dispatched);
        $this->assertSame('billing.trial_expiring', $dispatched[0]->type);
        $this->assertSame($owner->getId()->toRfc4122(), $dispatched[0]->userId);
    }

    #[Test]
    public function notifiesNoOneWhenTheOrgHasNoBillingAdmins(): void
    {
        $owner = new User('owner@example.com', 'Owner');
        $org = new Organization('Acme', $owner);
        $plan = new Plan('pro', 'Pro');
        $subscription = new Subscription($org, $plan, SubscriptionStatus::Trialing);

        $this->subscriptions->method('findTrialsEndingBetween')->willReturn([$subscription]);
        $this->userRoles->method('findUsersWithPermissionInOrg')->willReturn([]);

        $this->bus->expects($this->never())->method('dispatch');

        ($this->handler)(new NotifyTrialsExpiringSoonMessage());
    }
}
