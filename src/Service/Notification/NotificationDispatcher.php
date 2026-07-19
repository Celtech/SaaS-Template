<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\User;
use App\Enum\NotificationType;
use App\Message\Notification\SendNotificationMessage;
use App\Repository\NotificationPreferenceRepository;
use Symfony\Component\Messenger\MessageBusInterface;

final class NotificationDispatcher
{
    public function __construct(
        private readonly NotificationPreferenceRepository $preferences,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function dispatch(User $user, NotificationType $type, string $title, string $body, ?string $actionUrl = null): void
    {
        foreach ($this->resolveEnabledChannels($user, $type) as $channel) {
            $this->bus->dispatch(new SendNotificationMessage(
                userId: $user->getId()->toRfc4122(),
                type: $type->value,
                channel: $channel,
                title: $title,
                body: $body,
                actionUrl: $actionUrl,
            ));
        }
    }

    /** @return string[] */
    public function resolveEnabledChannels(User $user, NotificationType $type): array
    {
        $enabled = [];

        foreach ($type->supportedChannels() as $channel) {
            $preference = $this->preferences->findOneForUserTypeChannel($user, $type->value, $channel);
            $isEnabled = $preference?->isEnabled() ?? \in_array($channel, $type->defaultChannels(), true);

            if ($isEnabled) {
                $enabled[] = $channel;
            }
        }

        return $enabled;
    }
}
