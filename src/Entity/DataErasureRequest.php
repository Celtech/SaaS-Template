<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DataErasureStatus;
use App\Repository\DataErasureRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DataErasureRequestRepository::class)]
#[ORM\Table(name: 'data_erasure_requests')]
#[ORM\Index(columns: ['user_id'], name: 'idx_erasure_user')]
#[ORM\Index(columns: ['status'], name: 'idx_erasure_status')]
class DataErasureRequest
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: DataErasureStatus::class)]
    private DataErasureStatus $status = DataErasureStatus::Pending;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $errorContext = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    public function __construct(User $user, ?string $reason = null)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->reason = $reason;
        $this->requestedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getStatus(): DataErasureStatus
    {
        return $this->status;
    }

    public function markProcessing(): static
    {
        $this->status = DataErasureStatus::Processing;

        return $this;
    }

    public function markCompleted(): static
    {
        $this->status = DataErasureStatus::Completed;
        $this->processedAt = new \DateTimeImmutable();

        return $this;
    }

    /** @param array<string, mixed> $context */
    public function markFailed(array $context = []): static
    {
        $this->status = DataErasureStatus::Failed;
        $this->errorContext = $context;
        $this->processedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    /** @return array<string, mixed>|null */
    public function getErrorContext(): ?array
    {
        return $this->errorContext;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }
}
