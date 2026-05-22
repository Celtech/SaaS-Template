<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Backup\BackupCodeManagerInterface;

final class BackupCodeManager implements BackupCodeManagerInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function isBackupCode(object $user, string $code): bool
    {
        if (!$user instanceof User) {
            return false;
        }

        return $user->isBackupCode($code);
    }

    public function invalidateBackupCode(object $user, string $code): void
    {
        if (!$user instanceof User) {
            return;
        }

        $user->invalidateBackupCode($code);
        $this->em->flush();
    }
}
