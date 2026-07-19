<?php

declare(strict_types=1);

namespace App\MessageHandler\Notification;

use App\Entity\Notification;
use App\Message\Notification\SendNotificationMessage;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use App\Service\Notification\NotificationChannelInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final class SendNotificationHandler
{
    /** @param iterable<NotificationChannelInterface> $channels */
    public function __construct(
        private readonly UserRepository $users,
        private readonly NotificationRepository $notifications,
        #[AutowireIterator('app.notification_channel')]
        private readonly iterable $channels,
    ) {
    }

    public function __invoke(SendNotificationMessage $message): void
    {
        $user = $this->users->find(Uuid::fromString($message->userId));

        if ($user === null) {
            return;
        }

        $notification = new Notification(
            user: $user,
            type: $message->type,
            channel: $message->channel,
            title: $message->title,
            body: $message->body,
            actionUrl: $message->actionUrl,
        );
        $this->notifications->save($notification, flush: true);

        foreach ($this->channels as $channel) {
            if ($channel->supports($message->channel)) {
                $channel->send($notification);

                return;
            }
        }
    }
}
