<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Notification\Channel;

use App\Entity\Notification;
use App\Entity\User;
use App\Service\Notification\Channel\InAppNotificationChannel;
use App\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;

final class InAppNotificationChannelTest extends UnitTestCase
{
    private InAppNotificationChannel $channel;

    protected function setUp(): void
    {
        $this->channel = new InAppNotificationChannel();
    }

    #[Test]
    public function itSupportsOnlyTheInAppChannel(): void
    {
        $this->assertTrue($this->channel->supports('in_app'));
        $this->assertFalse($this->channel->supports('email'));
        $this->assertFalse($this->channel->supports('slack'));
    }

    #[Test]
    public function sendIsANoOp(): void
    {
        $user = new User('user@example.com', 'Test User');
        $notification = new Notification($user, 'billing.payment_failed', 'in_app', 'Title', 'Body');

        // The row is already persisted by SendNotificationHandler before this is
        // called — nothing further should happen, and nothing should throw.
        $this->channel->send($notification);

        $this->addToAssertionCount(1);
    }
}
