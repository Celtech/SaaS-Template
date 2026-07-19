<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler\Notification;

use App\Message\Mail\SendMailMessage;
use App\Message\Notification\SendNotificationMessage;
use App\MessageHandler\Notification\SendNotificationHandler;
use App\Repository\NotificationRepository;
use App\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class SendNotificationHandlerTest extends FunctionalTestCase
{
    private InMemoryTransport $transport;
    private SendNotificationHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $transport = static::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);
        $this->transport = $transport;
        $this->transport->reset();
        $this->handler = static::getContainer()->get(SendNotificationHandler::class);
    }

    #[Test]
    public function handlingAnInAppMessagePersistsAnUnreadNotificationAndQueuesNothing(): void
    {
        $user = $this->createUserWithOrg('notify-inapp@example.com');

        ($this->handler)(new SendNotificationMessage(
            userId: $user->getId()->toRfc4122(),
            type: 'billing.payment_failed',
            channel: 'in_app',
            title: 'Payment failed',
            body: 'Your invoice payment failed.',
        ));

        $notifications = static::getContainer()->get(NotificationRepository::class)->findInAppForUser($user);
        $this->assertCount(1, $notifications);
        $this->assertSame('Payment failed', $notifications[0]->getTitle());
        $this->assertFalse($notifications[0]->isRead());
        $this->assertSame(1, static::getContainer()->get(NotificationRepository::class)->countUnreadInApp($user));

        // in_app is a no-op driver — nothing further should have been queued.
        $this->assertCount(0, $this->transport->getSent());
    }

    #[Test]
    public function handlingAnEmailMessagePersistsARowAndQueuesTheEmail(): void
    {
        $user = $this->createUserWithOrg('notify-email@example.com');

        ($this->handler)(new SendNotificationMessage(
            userId: $user->getId()->toRfc4122(),
            type: 'billing.payment_failed',
            channel: 'email',
            title: 'Payment failed',
            body: 'Your invoice payment failed.',
            actionUrl: 'https://app.example.com/billing',
        ));

        // The email-channel row isn't an in_app row, so the in_app query shouldn't see it.
        $this->assertCount(0, static::getContainer()->get(NotificationRepository::class)->findInAppForUser($user));

        $sent = $this->transport->getSent();
        $this->assertCount(1, $sent);
        $mail = $sent[0]->getMessage();
        $this->assertInstanceOf(SendMailMessage::class, $mail);
        $this->assertSame($user->getEmail(), $mail->to);
        $this->assertSame('email/notification.html.twig', $mail->template);
        $this->assertSame('Payment failed', $mail->context['title']);
        $this->assertSame('https://app.example.com/billing', $mail->context['action_url']);
    }

    #[Test]
    public function handlingAMessageForADeletedUserDoesNothing(): void
    {
        ($this->handler)(new SendNotificationMessage(
            userId: Uuid::v7()->toRfc4122(),
            type: 'billing.payment_failed',
            channel: 'in_app',
            title: 'Payment failed',
            body: 'Your invoice payment failed.',
        ));

        $this->assertCount(0, $this->transport->getSent());
    }
}
