<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OAuthClientRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: OAuthClientRepository::class)]
#[ORM\Table(name: 'oauth_clients')]
class OAuthClient
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Organization $organization;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $description;

    /** Publicly shareable identifier. */
    #[ORM\Column(length: 80, unique: true)]
    private string $clientId;

    /** SHA-256 of the plaintext secret. Null for public (PKCE-only) clients. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $clientSecretHash;

    /** @var string[] */
    #[ORM\Column(type: 'json')]
    private array $redirectUris = [];

    /** @var string[] grant type identifiers */
    #[ORM\Column(type: 'json')]
    private array $allowedGrants = [];

    /** @var string[] OAuthScope values */
    #[ORM\Column(type: 'json')]
    private array $allowedScopes = [];

    /** Confidential clients authenticate with a secret; public clients use PKCE only. */
    #[ORM\Column]
    private bool $isConfidential = true;

    /** @var Collection<int, OAuthAccessToken> */
    #[ORM\OneToMany(targetEntity: OAuthAccessToken::class, mappedBy: 'client', cascade: ['remove'])]
    private Collection $accessTokens;

    /** @var Collection<int, OAuthRefreshToken> */
    #[ORM\OneToMany(targetEntity: OAuthRefreshToken::class, mappedBy: 'client', cascade: ['remove'])]
    private Collection $refreshTokens;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        string $clientId,
        string $name,
        ?Organization $organization = null,
    ) {
        $this->id = Uuid::v7();
        $this->clientId = $clientId;
        $this->name = $name;
        $this->organization = $organization;
        $this->description = null;
        $this->clientSecretHash = null;
        $this->accessTokens = new ArrayCollection();
        $this->refreshTokens = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOrganization(): ?Organization
    {
        return $this->organization;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function getClientSecretHash(): ?string
    {
        return $this->clientSecretHash;
    }

    public function setClientSecretHash(?string $hash): void
    {
        $this->clientSecretHash = $hash;
        $this->updatedAt = new DateTimeImmutable();
    }

    /** @return string[] */
    public function getRedirectUris(): array
    {
        return $this->redirectUris;
    }

    /** @param string[] $uris */
    public function setRedirectUris(array $uris): void
    {
        $this->redirectUris = array_values(array_filter($uris));
        $this->updatedAt = new DateTimeImmutable();
    }

    /** @return string[] */
    public function getAllowedGrants(): array
    {
        return $this->allowedGrants;
    }

    /** @param string[] $grants */
    public function setAllowedGrants(array $grants): void
    {
        $this->allowedGrants = array_values($grants);
        $this->updatedAt = new DateTimeImmutable();
    }

    /** @return string[] */
    public function getAllowedScopes(): array
    {
        return $this->allowedScopes;
    }

    /** @param string[] $scopes */
    public function setAllowedScopes(array $scopes): void
    {
        $this->allowedScopes = array_values($scopes);
        $this->updatedAt = new DateTimeImmutable();
    }

    public function isConfidential(): bool
    {
        return $this->isConfidential;
    }

    public function setIsConfidential(bool $isConfidential): void
    {
        $this->isConfidential = $isConfidential;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function supportsGrant(string $grant): bool
    {
        return \in_array($grant, $this->allowedGrants, true);
    }

    /** @param string[] $requested */
    public function scopesAreAllowed(array $requested): bool
    {
        foreach ($requested as $scope) {
            if (!\in_array($scope, $this->allowedScopes, true)) {
                return false;
            }
        }

        return true;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
