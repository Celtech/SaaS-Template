<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OAuthAuthorizationCodeRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: OAuthAuthorizationCodeRepository::class)]
#[ORM\Table(name: 'oauth_authorization_codes')]
#[ORM\Index(columns: ['code_hash'], name: 'idx_oauth_authorization_codes_hash')]
#[ORM\Index(columns: ['expires_at'], name: 'idx_oauth_authorization_codes_expiry')]
class OAuthAuthorizationCode
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /** SHA-256 of the plaintext code. The raw code is never stored. */
    #[ORM\Column(length: 64, unique: true)]
    private string $codeHash;

    #[ORM\ManyToOne(targetEntity: OAuthClient::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private OAuthClient $client;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Organization $organization;

    /** @var string[] */
    #[ORM\Column(type: 'json')]
    private array $scopes = [];

    /** Must exact-match the redirect_uri used at the token-exchange step (RFC 6749 §4.1.3). */
    #[ORM\Column(length: 500)]
    private string $redirectUri;

    /** Base64url-encoded SHA-256 PKCE challenge (RFC 7636). Only the "S256" method is supported. */
    #[ORM\Column(length: 128)]
    private string $codeChallenge;

    #[ORM\Column]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $usedAt;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    /** @param string[] $scopes */
    public function __construct(
        string $codeHash,
        OAuthClient $client,
        User $user,
        ?Organization $organization,
        array $scopes,
        string $redirectUri,
        string $codeChallenge,
        DateTimeImmutable $expiresAt,
    ) {
        $this->id = Uuid::v7();
        $this->codeHash = $codeHash;
        $this->client = $client;
        $this->user = $user;
        $this->organization = $organization;
        $this->scopes = $scopes;
        $this->redirectUri = $redirectUri;
        $this->codeChallenge = $codeChallenge;
        $this->expiresAt = $expiresAt;
        $this->usedAt = null;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCodeHash(): string
    {
        return $this->codeHash;
    }

    public function getClient(): OAuthClient
    {
        return $this->client;
    }

    public function getUser(): User
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

    public function getRedirectUri(): string
    {
        return $this->redirectUri;
    }

    public function getCodeChallenge(): string
    {
        return $this->codeChallenge;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getUsedAt(): ?DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function markUsed(): void
    {
        $this->usedAt = new DateTimeImmutable();
    }

    public function isExpired(): bool
    {
        return $this->expiresAt <= new DateTimeImmutable();
    }

    public function isUsed(): bool
    {
        return $this->usedAt !== null;
    }

    public function isActive(): bool
    {
        return !$this->isExpired() && !$this->isUsed();
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
