<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OAuthRefreshTokenRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: OAuthRefreshTokenRepository::class)]
#[ORM\Table(name: 'oauth_refresh_tokens')]
#[ORM\Index(columns: ['token_hash'], name: 'idx_oauth_refresh_tokens_hash')]
class OAuthRefreshToken
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\ManyToOne(targetEntity: OAuthClient::class, inversedBy: 'refreshTokens')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private OAuthClient $client;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $user;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Organization $organization;

    /** @var string[] */
    #[ORM\Column(type: 'json')]
    private array $scopes = [];

    #[ORM\Column]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $revokedAt;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    /** @param string[] $scopes */
    public function __construct(
        string $tokenHash,
        OAuthClient $client,
        ?User $user,
        ?Organization $organization,
        array $scopes,
        DateTimeImmutable $expiresAt,
    ) {
        $this->id = Uuid::v7();
        $this->tokenHash = $tokenHash;
        $this->client = $client;
        $this->user = $user;
        $this->organization = $organization;
        $this->scopes = $scopes;
        $this->expiresAt = $expiresAt;
        $this->revokedAt = null;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getClient(): OAuthClient
    {
        return $this->client;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getOrganization(): ?Organization
    {
        return $this->organization;
    }

    /** @return string[] */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getRevokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(): void
    {
        $this->revokedAt = new DateTimeImmutable();
    }

    public function isExpired(): bool
    {
        return $this->expiresAt <= new DateTimeImmutable();
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function isActive(): bool
    {
        return !$this->isExpired() && !$this->isRevoked();
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
