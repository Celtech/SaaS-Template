<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Notification;

use App\Entity\NotificationPreference;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Message\Notification\SendNotificationMessage;
use App\Repository\NotificationPreferenceRepository;
use App\Service\Notification\NotificationDispatcher;
use App\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class NotificationDispatcherTest extends UnitTestCase
{
    private NotificationPreferenceRepository&MockObject $preferences;
    private MessageBusInterface&MockObject $bus;
    private NotificationDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->preferences = $this->createMock(NotificationPreferenceRepository::class);
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->dispatcher = new NotificationDispatcher($this->preferences, $this->bus);
    }

    #[Test]
    public function resolveEnabledChannelsUsesTypeDefaultsWhenNoPreferenceExists(): void
    {
        $user = new User('user@example.com', 'Test User');
        $this->preferences->method('findOneForUserTypeChannel')->willReturn(null);

        // BillingPaymentFailed defaults to both in_app and email.
        $channels = $this->dispatcher->resolveEnabledChannels($user, NotificationType::BillingPaymentFailed);

        $this->assertSame(['in_app', 'email'], $channels);
    }

    #[Test]
    public function resolveEnabledChannelsExcludesChannelsOffByDefaultWithNoPreference(): void
    {
        $user = new User('user@example.com', 'Test User');
        $this->preferences->method('findOneForUserTypeChannel')->willReturn(null);

        // SecurityNewLogin is opt-in — off by default on every channel.
        $channels = $this->dispatcher->resolveEnabledChannels($user, NotificationType::SecurityNewLogin);

        $this->assertSame([], $channels);
    }

    #[Test]
    public function resolveEnabledChannelsHonorsAnExplicitPreferenceOverride(): void
    {
        $user = new User('user@example.com', 'Test User');

        $this->preferences->method('findOneForUserTypeChannel')
            ->willReturnCallback(static function (User $u, string $type, string $channel) use ($user) {
                if ($channel === 'email') {
                    return new NotificationPreference($user, $type, 'email', false);
                }

                return null;
            });

        // Default is [in_app, email]; explicit override disables email only.
        $channels = $this->dispatcher->resolveEnabledChannels($user, NotificationType::BillingPaymentFailed);

        $this->assertSame(['in_app'], $channels);
    }

    #[Test]
    public function resolveEnabledChannelsHonorsAnExplicitOptInOverride(): void
    {
        $user = new User('user@example.com', 'Test User');

        $this->preferences->method('findOneForUserTypeChannel')
            ->willReturnCallback(static function (User $u, string $type, string $channel) use ($user) {
                if ($channel === 'email') {
                    return new NotificationPreference($user, $type, 'email', true);
                }

                return null;
            });

        // SecurityNewLogin defaults to off everywhere; explicit override opts email in.
        $channels = $this->dispatcher->resolveEnabledChannels($user, NotificationType::SecurityNewLogin);

        $this->assertSame(['email'], $channels);
    }

    #[Test]
    public function dispatchSendsOneMessagePerEnabledChannel(): void
    {
        $user = new User('user@example.com', 'Test User');
        $this->preferences->method('findOneForUserTypeChannel')->willReturn(null);

        $dispatched = [];
        $this->bus->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(static function (object $message) use (&$dispatched): Envelope {
                $dispatched[] = $message;

                return new Envelope($message);
            });

        $this->dispatcher->dispatch($user, NotificationType::BillingPaymentFailed, 'Payment failed', 'Your invoice payment failed.');

        $this->assertCount(2, $dispatched);
        $this->assertContainsOnlyInstancesOf(SendNotificationMessage::class, $dispatched);
        $this->assertSame(['in_app', 'email'], array_map(static fn (SendNotificationMessage $m) => $m->channel, $dispatched));
        $this->assertSame('Payment failed', $dispatched[0]->title);
        $this->assertSame('Your invoice payment failed.', $dispatched[0]->body);
    }

    #[Test]
    public function dispatchSendsNothingWhenNoChannelsAreEnabled(): void
    {
        $user = new User('user@example.com', 'Test User');
        $this->preferences->method('findOneForUserTypeChannel')->willReturn(null);

        $this->bus->expects($this->never())->method('dispatch');

        $this->dispatcher->dispatch($user, NotificationType::SecurityNewLogin, 'New login', 'A new device signed in.');
    }
}
