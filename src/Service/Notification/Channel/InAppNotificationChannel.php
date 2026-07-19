<?php

declare(strict_types=1);

namespace App\Service\Notification\Channel;

use App\Entity\Notification;
use App\Service\Notification\NotificationChannelInterface;

/** No-op: SendNotificationHandler already persists the Notification row, which is itself the in-app delivery. */
final class InAppNotificationChannel implements NotificationChannelInterface
{
    public function supports(string $channel): bool
    {
        return $channel === 'in_app';
    }

    public function send(Notification $notification): void
    {
    }
}
