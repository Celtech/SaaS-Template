<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NotificationRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One row per (user, type, channel) delivery attempt — append-only except for
 * readAt, which is the only field ever updated in place. A single logical event
 * enabled on two channels produces two rows (see SendNotificationHandler).
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notifications')]
#[ORM\Index(columns: ['user_id', 'channel', 'read_at'], name: 'idx_notifications_user_channel_read')]
class Notification
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** NotificationType::value */
    #[ORM\Column(length: 100)]
    private string $type;

    /** Delivery channel identifier, e.g. 'in_app', 'email' — open-ended, not a closed enum. */
    #[ORM\Column(length: 50)]
    private string $channel;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $actionUrl;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $readAt = null;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    public function __construct(
        User $user,
        string $type,
        string $channel,
        string $title,
        string $body,
        ?string $actionUrl = null,
    ) {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->type = $type;
        $this->channel = $channel;
        $this->title = $title;
        $this->body = $body;
        $this->actionUrl = $actionUrl;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getActionUrl(): ?string
    {
        return $this->actionUrl;
    }

    public function getReadAt(): ?DateTimeImmutable
    {
        return $this->readAt;
    }

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }

    public function markAsRead(): void
    {
        $this->readAt ??= new DateTimeImmutable();
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
