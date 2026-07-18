<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OAuthDeviceCodeRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** RFC 8628 — OAuth 2.0 Device Authorization Grant. */
#[ORM\Entity(repositoryClass: OAuthDeviceCodeRepository::class)]
#[ORM\Table(name: 'oauth_device_codes')]
#[ORM\Index(columns: ['device_code_hash'], name: 'idx_oauth_device_codes_device_hash')]
#[ORM\Index(columns: ['user_code_hash'], name: 'idx_oauth_device_codes_user_hash')]
#[ORM\Index(columns: ['expires_at'], name: 'idx_oauth_device_codes_expiry')]
class OAuthDeviceCode
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /** SHA-256 of the plaintext device_code polled by the device. */
    #[ORM\Column(length: 64, unique: true)]
    private string $deviceCodeHash;

    /** SHA-256 of the plaintext user_code (normalized upper-case) shown to and entered by the user. */
    #[ORM\Column(length: 64, unique: true)]
    private string $userCodeHash;

    #[ORM\ManyToOne(targetEntity: OAuthClient::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private OAuthClient $client;

    /** Set once the user approves the request. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $user;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Organization $organization;

    /** @var string[] */
    #[ORM\Column(type: 'json')]
    private array $scopes = [];

    /** Minimum seconds the polling device must wait between requests (RFC 8628 §3.2). */
    #[ORM\Column]
    private int $interval;

    #[ORM\Column]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $approvedAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $deniedAt;

    /** Set once the device has successfully exchanged this code for tokens. */
    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $consumedAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $lastPolledAt;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    /** @param string[] $scopes */
    public function __construct(
        string $deviceCodeHash,
        string $userCodeHash,
        OAuthClient $client,
        array $scopes,
        int $interval,
        DateTimeImmutable $expiresAt,
    ) {
        $this->id = Uuid::v7();
        $this->deviceCodeHash = $deviceCodeHash;
        $this->userCodeHash = $userCodeHash;
        $this->client = $client;
        $this->user = null;
        $this->organization = null;
        $this->scopes = $scopes;
        $this->interval = $interval;
        $this->expiresAt = $expiresAt;
        $this->approvedAt = null;
        $this->deniedAt = null;
        $this->consumedAt = null;
        $this->lastPolledAt = null;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDeviceCodeHash(): string
    {
        return $this->deviceCodeHash;
    }

    public function getUserCodeHash(): string
    {
        return $this->userCodeHash;
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

    public function getInterval(): int
    {
        return $this->interval;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getLastPolledAt(): ?DateTimeImmutable
    {
        return $this->lastPolledAt;
    }

    public function recordPoll(): void
    {
        $this->lastPolledAt = new DateTimeImmutable();
    }

    public function markApproved(User $user, ?Organization $organization): void
    {
        $this->user = $user;
        $this->organization = $organization;
        $this->approvedAt = new DateTimeImmutable();
    }

    public function markDenied(): void
    {
        $this->deniedAt = new DateTimeImmutable();
    }

    public function markConsumed(): void
    {
        $this->consumedAt = new DateTimeImmutable();
    }

    public function isExpired(): bool
    {
        return $this->expiresAt <= new DateTimeImmutable();
    }

    public function isApproved(): bool
    {
        return $this->approvedAt !== null;
    }

    public function isDenied(): bool
    {
        return $this->deniedAt !== null;
    }

    public function isConsumed(): bool
    {
        return $this->consumedAt !== null;
    }

    public function isPending(): bool
    {
        return !$this->isExpired() && !$this->isApproved() && !$this->isDenied();
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
