<?php

declare(strict_types=1);

namespace App\Service\Notification\Channel;

use App\Entity\Notification;
use App\Message\Mail\SendMailMessage;
use App\Service\Notification\NotificationChannelInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class EmailNotificationChannel implements NotificationChannelInterface
{
    public function __construct(private readonly MessageBusInterface $bus)
    {
    }

    public function supports(string $channel): bool
    {
        return $channel === 'email';
    }

    public function send(Notification $notification): void
    {
        $this->bus->dispatch(new SendMailMessage(
            template: 'email/notification.html.twig',
            to: $notification->getUser()->getEmail(),
            context: [
                'title' => $notification->getTitle(),
                'body' => $notification->getBody(),
                'action_url' => $notification->getActionUrl(),
            ],
        ));
    }
}
