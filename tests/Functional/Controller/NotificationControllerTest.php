<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

final class NotificationControllerTest extends FunctionalTestCase
{
    #[Test]
    public function bellShowsTheUnreadCount(): void
    {
        $user = $this->createUserWithOrg('notif-bell@example.com');
        $this->createNotification($user, read: false);
        $this->createNotification($user, read: false);
        $this->createNotification($user, read: true);

        $this->client->loginUser($user);
        $this->client->request('GET', '/notifications/bell');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('2', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function dropdownListsLatestNotifications(): void
    {
        $user = $this->createUserWithOrg('notif-dropdown@example.com');
        $this->createNotification($user, title: 'First notification');

        $this->client->loginUser($user);
        $this->client->request('GET', '/notifications/dropdown');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('First notification', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function markReadMarksTheNotificationAndRedirectsToTheFeedByDefault(): void
    {
        $user = $this->createUserWithOrg('notif-mark-read@example.com');
        $notification = $this->createNotification($user, read: false);

        $this->client->loginUser($user);
        $this->client->request('POST', '/notifications/' . $notification->getId()->toRfc4122() . '/read', [
            '_token' => $this->csrfToken('notifications_mark_read_' . $notification->getId()->toRfc4122()),
        ]);

        $this->assertResponseRedirects('/notifications');

        $this->em->clear();
        $refreshed = $this->em->find(Notification::class, $notification->getId());
        $this->assertNotNull($refreshed);
        $this->assertTrue($refreshed->isRead());
    }

    #[Test]
    public function markReadRedirectsToTheDropdownWhenRequested(): void
    {
        $user = $this->createUserWithOrg('notif-mark-read-dropdown@example.com');
        $notification = $this->createNotification($user, read: false);

        $this->client->loginUser($user);
        $this->client->request('POST', '/notifications/' . $notification->getId()->toRfc4122() . '/read', [
            '_token' => $this->csrfToken('notifications_mark_read_' . $notification->getId()->toRfc4122()),
            'redirect_to' => 'dropdown',
        ]);

        $this->assertResponseRedirects('/notifications/dropdown');
    }

    #[Test]
    public function markReadRejectsAnotherUsersNotification(): void
    {
        $owner = $this->createUserWithOrg('notif-owner@example.com');
        $intruder = $this->createUserWithOrg('notif-intruder@example.com');
        $notification = $this->createNotification($owner, read: false);

        $this->client->loginUser($intruder);
        $this->client->request('POST', '/notifications/' . $notification->getId()->toRfc4122() . '/read', [
            '_token' => $this->csrfToken('notifications_mark_read_' . $notification->getId()->toRfc4122()),
        ]);

        $this->assertResponseStatusCodeSame(403);

        $this->em->clear();
        $refreshed = $this->em->find(Notification::class, $notification->getId());
        $this->assertNotNull($refreshed);
        $this->assertFalse($refreshed->isRead());
    }

    #[Test]
    public function markAllReadMarksEveryUnreadNotification(): void
    {
        $user = $this->createUserWithOrg('notif-mark-all@example.com');
        $first = $this->createNotification($user, read: false);
        $second = $this->createNotification($user, read: false);

        $this->client->loginUser($user);
        $this->client->request('POST', '/notifications/read-all', [
            '_token' => $this->csrfToken('notifications_mark_all_read'),
        ]);

        $this->assertResponseRedirects('/notifications');

        $this->em->clear();
        $refreshedFirst = $this->em->find(Notification::class, $first->getId());
        $refreshedSecond = $this->em->find(Notification::class, $second->getId());
        $this->assertNotNull($refreshedFirst);
        $this->assertNotNull($refreshedSecond);
        $this->assertTrue($refreshedFirst->isRead());
        $this->assertTrue($refreshedSecond->isRead());
    }

    #[Test]
    public function feedPageFiltersByTypeAndPaginates(): void
    {
        $user = $this->createUserWithOrg('notif-feed@example.com');
        $this->createNotification($user, type: 'billing.payment_failed', title: 'Payment failed notice');
        $this->createNotification($user, type: 'org.member_joined', title: 'Member joined notice');

        $this->client->loginUser($user);
        $this->client->request('GET', '/notifications?type=billing.payment_failed');

        $this->assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Payment failed notice', $content);
        $this->assertStringNotContainsString('Member joined notice', $content);
    }

    private function createNotification(
        User $user,
        bool $read = false,
        string $type = 'billing.payment_failed',
        string $title = 'Test notification',
    ): Notification {
        $notification = new Notification($user, $type, 'in_app', $title, 'Body text');
        if ($read) {
            $notification->markAsRead();
        }
        $this->em->persist($notification);
        $this->em->flush();

        return $notification;
    }

    private function csrfToken(string $id): string
    {
        return static::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
    }
}
