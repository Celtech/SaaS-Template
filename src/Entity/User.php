<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Scheb\TwoFactorBundle\Model\Email\TwoFactorInterface as EmailTwoFactorInterface;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface as TotpTwoFactorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\Index(columns: ['email'], name: 'idx_users_email')]
#[ORM\Index(columns: ['deleted_at'], name: 'idx_users_deleted_at')]
#[ORM\UniqueConstraint(name: 'uniq_users_email', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface, TotpTwoFactorInterface, EmailTwoFactorInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 180)]
    private string $email;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $name;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column(type: Types::STRING, nullable: true)]
    private ?string $password = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $avatarUrl = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $suspendedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $deletedAt = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $failedLoginCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lockedUntil = null;

    #[ORM\Column(type: Types::STRING, nullable: true)]
    private ?string $totpSecret = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $totpEnabled = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $emailAuthEnabled = false;

    #[ORM\Column(type: Types::STRING, length: 6, nullable: true)]
    private ?string $emailAuthCode = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(string $email, string $name)
    {
        $this->id = Uuid::v7();
        $this->email = strtolower(trim($email));
        $this->name = $name;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = strtolower(trim($email));
        $this->touch();

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        $this->touch();

        return $this;
    }

    /** @return non-empty-string */
    public function getUserIdentifier(): string
    {
        \assert($this->email !== '');

        return $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        $this->touch();

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        $this->touch();

        return $this;
    }

    public function getAvatarUrl(): ?string
    {
        return $this->avatarUrl;
    }

    public function setAvatarUrl(?string $avatarUrl): static
    {
        $this->avatarUrl = $avatarUrl;
        $this->touch();

        return $this;
    }

    public function getEmailVerifiedAt(): ?DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function markEmailVerified(): static
    {
        $this->emailVerifiedAt = new DateTimeImmutable();
        $this->touch();

        return $this;
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerifiedAt !== null;
    }

    public function getSuspendedAt(): ?DateTimeImmutable
    {
        return $this->suspendedAt;
    }

    public function isSuspended(): bool
    {
        return $this->suspendedAt !== null;
    }

    public function suspend(): static
    {
        $this->suspendedAt = new DateTimeImmutable();
        $this->touch();

        return $this;
    }

    public function unsuspend(): static
    {
        $this->suspendedAt = null;
        $this->touch();

        return $this;
    }

    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function softDelete(): static
    {
        $this->deletedAt = new DateTimeImmutable();
        $this->touch();

        return $this;
    }

    public function restore(): static
    {
        $this->deletedAt = null;
        $this->suspendedAt = null;
        $this->touch();

        return $this;
    }

    public function isActive(): bool
    {
        return $this->deletedAt === null && $this->suspendedAt === null;
    }

    public function getFailedLoginCount(): int
    {
        return $this->failedLoginCount;
    }

    public function incrementFailedLoginCount(): static
    {
        ++$this->failedLoginCount;
        $this->touch();

        return $this;
    }

    public function resetFailedLoginCount(): static
    {
        $this->failedLoginCount = 0;
        $this->touch();

        return $this;
    }

    public function getLockedUntil(): ?DateTimeImmutable
    {
        return $this->lockedUntil;
    }

    public function lockUntil(DateTimeImmutable $until): static
    {
        $this->lockedUntil = $until;
        $this->touch();

        return $this;
    }

    public function unlock(): static
    {
        $this->lockedUntil = null;
        $this->failedLoginCount = 0;
        $this->touch();

        return $this;
    }

    public function isLocked(): bool
    {
        return $this->lockedUntil !== null && $this->lockedUntil > new DateTimeImmutable();
    }

    public function isTotpAuthenticationEnabled(): bool
    {
        return $this->totpEnabled && $this->totpSecret !== null;
    }

    public function getTotpAuthenticationUsername(): string
    {
        return $this->email;
    }

    public function getTotpAuthenticationConfiguration(): ?TotpConfigurationInterface
    {
        if ($this->totpSecret === null) {
            return null;
        }

        return new TotpConfiguration($this->totpSecret, TotpConfiguration::ALGORITHM_SHA1, 30, 6);
    }

    public function getTotpSecret(): ?string
    {
        return $this->totpSecret;
    }

    public function enableTotp(string $secret): static
    {
        $this->totpSecret = $secret;
        $this->totpEnabled = true;
        $this->touch();

        return $this;
    }

    public function disableTotp(): static
    {
        $this->totpSecret = null;
        $this->totpEnabled = false;
        $this->touch();

        return $this;
    }

    public function isTotpEnabled(): bool
    {
        return $this->totpEnabled;
    }

    public function isEmailAuthEnabled(): bool
    {
        return $this->emailAuthEnabled;
    }

    public function getEmailAuthRecipient(): string
    {
        return $this->email;
    }

    public function getEmailAuthCode(): ?string
    {
        return $this->emailAuthCode;
    }

    public function setEmailAuthCode(string $authCode): void
    {
        $this->emailAuthCode = $authCode !== '' ? $authCode : null;
    }

    public function enableEmailAuth(): static
    {
        $this->emailAuthEnabled = true;
        $this->touch();

        return $this;
    }

    public function disableEmailAuth(): static
    {
        $this->emailAuthEnabled = false;
        $this->emailAuthCode = null;
        $this->touch();

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function eraseCredentials(): void
    {
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
