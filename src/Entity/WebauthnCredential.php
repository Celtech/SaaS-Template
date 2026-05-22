<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WebauthnCredentialRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: WebauthnCredentialRepository::class)]
#[ORM\Table(name: 'webauthn_credentials')]
#[ORM\Index(columns: ['credential_id'], name: 'idx_webauthn_credential_id')]
class WebauthnCredential
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 512, unique: true)]
    private string $credentialId;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $credentialSource;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $lastUsedAt = null;

    /** @param array<string, mixed> $credentialSource */
    public function __construct(User $user, string $name, string $credentialId, array $credentialSource)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->name = $name;
        $this->credentialId = $credentialId;
        $this->credentialSource = $credentialSource;
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

    public function getName(): string
    {
        return $this->name;
    }

    public function getCredentialId(): string
    {
        return $this->credentialId;
    }

    /** @return array<string, mixed> */
    public function getCredentialSource(): array
    {
        return $this->credentialSource;
    }

    /** @param array<string, mixed> $credentialSource */
    public function setCredentialSource(array $credentialSource): void
    {
        $this->credentialSource = $credentialSource;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(DateTimeImmutable $lastUsedAt): void
    {
        $this->lastUsedAt = $lastUsedAt;
    }
}
