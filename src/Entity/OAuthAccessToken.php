<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OAuthAccessTokenRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: OAuthAccessTokenRepository::class)]
#[ORM\Table(name: 'oauth_access_tokens')]
#[ORM\Index(columns: ['token_hash'], name: 'idx_oauth_access_tokens_hash')]
#[ORM\Index(columns: ['expires_at'], name: 'idx_oauth_access_tokens_expiry')]
class OAuthAccessToken
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /** SHA-256 of the plaintext token. The raw token is never stored. */
    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\ManyToOne(targetEntity: OAuthClient::class, inversedBy: 'accessTokens')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private OAuthClient $client;

    /** Null for Client Credentials (M2M) tokens. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $user;

    /** The org scope for this token — always set, derived from the client or user. */
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

    public function hasScope(string $scope): bool
    {
        return \in_array($scope, $this->scopes, true);
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
