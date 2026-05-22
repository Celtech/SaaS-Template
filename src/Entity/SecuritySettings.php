<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SecuritySettingsRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SecuritySettingsRepository::class)]
#[ORM\Table(name: 'security_settings')]
class SecuritySettings
{
    public const int SINGLETON_ID = 1;
    public const int DEFAULT_MAX_FAILED_ATTEMPTS = 5;
    public const int DEFAULT_LOCKOUT_MINUTES = 15;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id = self::SINGLETON_ID;

    #[ORM\Column(type: 'integer')]
    private int $maxFailedAttempts = self::DEFAULT_MAX_FAILED_ATTEMPTS;

    #[ORM\Column(type: 'integer')]
    private int $lockoutMinutes = self::DEFAULT_LOCKOUT_MINUTES;

    public function getId(): int
    {
        return $this->id;
    }

    public function getMaxFailedAttempts(): int
    {
        return $this->maxFailedAttempts;
    }

    public function setMaxFailedAttempts(int $maxFailedAttempts): void
    {
        $this->maxFailedAttempts = $maxFailedAttempts;
    }

    public function getLockoutMinutes(): int
    {
        return $this->lockoutMinutes;
    }

    public function setLockoutMinutes(int $lockoutMinutes): void
    {
        $this->lockoutMinutes = $lockoutMinutes;
    }
}
