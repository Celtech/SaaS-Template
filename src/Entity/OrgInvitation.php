<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrgInvitationRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: OrgInvitationRepository::class)]
#[ORM\Table(name: 'org_invitations')]
#[ORM\Index(columns: ['email'], name: 'idx_org_invitations_email')]
class OrgInvitation
{
    public const TTL_HOURS = 48;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Organization $organization;

    #[ORM\Column(length: 255)]
    private string $email;

    #[ORM\Column(length: 64, unique: true)]
    private string $token;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $invitedBy;

    #[ORM\Column]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $acceptedAt = null;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    public function __construct(Organization $organization, string $email, User $invitedBy)
    {
        $this->id = Uuid::v7();
        $this->organization = $organization;
        $this->email = strtolower(trim($email));
        $this->invitedBy = $invitedBy;
        $this->token = bin2hex(random_bytes(32));
        $this->expiresAt = new DateTimeImmutable(\sprintf('+%d hours', self::TTL_HOURS));
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getInvitedBy(): User
    {
        return $this->invitedBy;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getAcceptedAt(): ?DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function accept(): void
    {
        $this->acceptedAt = new DateTimeImmutable();
    }

    public function isAccepted(): bool
    {
        return $this->acceptedAt !== null;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new DateTimeImmutable();
    }

    public function isPending(): bool
    {
        return !$this->isAccepted() && !$this->isExpired();
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
