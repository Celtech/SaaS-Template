<?php

declare(strict_types=1);

namespace App\Message\Notification;

final readonly class SendNotificationMessage
{
    public function __construct(
        public string $userId,
        public string $type,
        public string $channel,
        public string $title,
        public string $body,
        public ?string $actionUrl = null,
    ) {
    }
}
