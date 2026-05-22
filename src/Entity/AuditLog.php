<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuditLogRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(columns: ['actor_id'], name: 'idx_audit_log_actor')]
#[ORM\Index(columns: ['subject_id', 'subject_type'], name: 'idx_audit_log_subject')]
#[ORM\Index(columns: ['action'], name: 'idx_audit_log_action')]
#[ORM\Index(columns: ['created_at'], name: 'idx_audit_log_created_at')]
class AuditLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
    private ?string $actorId = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $actorType = null;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $action;

    #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
    private ?string $subjectId = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $subjectType = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $oldValue = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $newValue = null;

    #[ORM\Column(type: Types::STRING, length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $impersonationSessionId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(
        string $action,
        ?string $actorId = null,
        ?string $actorType = null,
        ?string $subjectId = null,
        ?string $subjectType = null,
        ?array $oldValue = null,
        ?array $newValue = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?Uuid $impersonationSessionId = null,
    ) {
        $this->id = Uuid::v7();
        $this->action = $action;
        $this->actorId = $actorId;
        $this->actorType = $actorType;
        $this->subjectId = $subjectId;
        $this->subjectType = $subjectType;
        $this->oldValue = $oldValue;
        $this->newValue = $newValue;
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent;
        $this->impersonationSessionId = $impersonationSessionId;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getActorId(): ?string
    {
        return $this->actorId;
    }

    public function getActorType(): ?string
    {
        return $this->actorType;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getSubjectId(): ?string
    {
        return $this->subjectId;
    }

    public function getSubjectType(): ?string
    {
        return $this->subjectType;
    }

    public function getOldValue(): ?array
    {
        return $this->oldValue;
    }

    public function getNewValue(): ?array
    {
        return $this->newValue;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getImpersonationSessionId(): ?Uuid
    {
        return $this->impersonationSessionId;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
