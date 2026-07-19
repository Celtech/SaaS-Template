<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NotificationPreferenceRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** Explicit per-user opt-in/out for one (notification type, channel) pair. Absence means the type's default applies. */
#[ORM\Entity(repositoryClass: NotificationPreferenceRepository::class)]
#[ORM\Table(name: 'notification_preferences')]
#[ORM\UniqueConstraint(name: 'uniq_notification_preference', columns: ['user_id', 'notification_type', 'channel'])]
class NotificationPreference
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** NotificationType::value */
    #[ORM\Column(length: 100)]
    private string $notificationType;

    #[ORM\Column(length: 50)]
    private string $channel;

    #[ORM\Column]
    private bool $enabled;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    public function __construct(User $user, string $notificationType, string $channel, bool $enabled)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->notificationType = $notificationType;
        $this->channel = $channel;
        $this->enabled = $enabled;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getNotificationType(): string
    {
        return $this->notificationType;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
