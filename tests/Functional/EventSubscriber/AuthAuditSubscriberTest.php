<?php

declare(strict_types=1);

namespace App\Tests\Functional\EventSubscriber;

use App\Entity\NotificationPreference;
use App\Entity\User;
use App\Entity\UserSession;
use App\Enum\NotificationType;
use App\Message\Notification\SendNotificationMessage;
use App\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class AuthAuditSubscriberTest extends FunctionalTestCase
{
    private InMemoryTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $transport = static::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);
        $this->transport = $transport;
        $this->transport->reset();
    }

    #[Test]
    public function loggingInFromAnUnseenIpNotifiesAnOptedInUser(): void
    {
        $user = $this->createUserWithOrg('new-login-notify@example.com');
        // security.new_login is opt-in and off by default — explicitly enable it.
        $this->em->persist(new NotificationPreference($user, NotificationType::SecurityNewLogin->value, 'in_app', true));
        $this->em->flush();

        $this->client->request('POST', '/auth/login', [
            'email' => 'new-login-notify@example.com',
            'password' => 'Password123!',
            '_csrf_token' => 'ignored-in-test',
        ]);

        $this->assertResponseRedirects();

        $sent = $this->notificationMessagesFor($user);
        $this->assertCount(1, $sent);
        $this->assertSame('security.new_login', $sent[0]->type);
    }

    #[Test]
    public function loggingInFromAKnownIpDoesNotNotify(): void
    {
        $user = $this->createUserWithOrg('known-login-notify@example.com');
        $this->em->persist(new NotificationPreference($user, NotificationType::SecurityNewLogin->value, 'in_app', true));
        // Test client requests use 127.0.0.1 — pre-record a session from that IP.
        $this->em->persist(new UserSession($user, 'prior-session-hash', '127.0.0.1', 'prior-agent'));
        $this->em->flush();

        $this->client->request('POST', '/auth/login', [
            'email' => 'known-login-notify@example.com',
            'password' => 'Password123!',
            '_csrf_token' => 'ignored-in-test',
        ]);

        $this->assertResponseRedirects();
        $this->assertCount(0, $this->notificationMessagesFor($user));
    }

    #[Test]
    public function loggingInWithoutOptingInDoesNotQueueAnything(): void
    {
        $user = $this->createUserWithOrg('no-optin-login@example.com');

        $this->client->request('POST', '/auth/login', [
            'email' => 'no-optin-login@example.com',
            'password' => 'Password123!',
            '_csrf_token' => 'ignored-in-test',
        ]);

        $this->assertResponseRedirects();
        $this->assertCount(0, $this->notificationMessagesFor($user));
    }

    /** @return SendNotificationMessage[] */
    private function notificationMessagesFor(User $user): array
    {
        $messages = [];

        foreach ($this->transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof SendNotificationMessage && $message->userId === $user->getId()->toRfc4122()) {
                $messages[] = $message;
            }
        }

        return $messages;
    }
}
