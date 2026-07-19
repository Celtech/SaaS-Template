<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Notification;

/**
 * Adding a new delivery channel (Slack, Discord, SMS) means implementing this
 * interface and tagging the service 'app.notification_channel' — no changes
 * to NotificationDispatcher or SendNotificationHandler required.
 */
interface NotificationChannelInterface
{
    public function supports(string $channel): bool;

    public function send(Notification $notification): void;
}
