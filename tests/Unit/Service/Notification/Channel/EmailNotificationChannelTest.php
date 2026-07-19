<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Notification\Channel;

use App\Entity\Notification;
use App\Entity\User;
use App\Message\Mail\SendMailMessage;
use App\Service\Notification\Channel\EmailNotificationChannel;
use App\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class EmailNotificationChannelTest extends UnitTestCase
{
    private MessageBusInterface&MockObject $bus;
    private EmailNotificationChannel $channel;

    protected function setUp(): void
    {
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->channel = new EmailNotificationChannel($this->bus);
    }

    #[Test]
    public function itSupportsOnlyTheEmailChannel(): void
    {
        $this->assertTrue($this->channel->supports('email'));
        $this->assertFalse($this->channel->supports('in_app'));
        $this->assertFalse($this->channel->supports('slack'));
    }

    #[Test]
    public function sendDispatchesASendMailMessageWithTheNotificationContent(): void
    {
        $user = new User('user@example.com', 'Test User');
        $notification = new Notification($user, 'billing.payment_failed', 'email', 'Payment failed', 'Your invoice payment failed.', 'https://app.example.com/billing');

        $sent = null;
        $this->bus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(static function (object $message) use (&$sent): Envelope {
                $sent = $message;

                return new Envelope($message);
            });

        $this->channel->send($notification);

        $this->assertInstanceOf(SendMailMessage::class, $sent);
        $this->assertSame('email/notification.html.twig', $sent->template);
        $this->assertSame('user@example.com', $sent->to);
        $this->assertSame('Payment failed', $sent->context['title']);
        $this->assertSame('Your invoice payment failed.', $sent->context['body']);
        $this->assertSame('https://app.example.com/billing', $sent->context['action_url']);
    }
}
