<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Audit\AuditLogger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

class AccountLockoutService
{
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES = 15;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function getUserByEmail(string $email): ?User
    {
        return $this->userRepository->findByEmail($email);
    }

    public function onSuccess(User $user): void
    {
        if ($user->getFailedLoginCount() > 0) {
            $user->resetFailedLoginCount();
            $this->em->flush();
        }
    }

    public function onFailure(string $email): void
    {
        $user = $this->userRepository->findByEmail($email);

        if ($user === null) {
            $this->auditLogger->logAuth('login.failure', null, 'user', ['email' => $email, 'reason' => 'unknown_email']);

            return;
        }

        $user->incrementFailedLoginCount();

        if ($user->getFailedLoginCount() >= self::MAX_ATTEMPTS) {
            $until = new DateTimeImmutable(\sprintf('+%d minutes', self::LOCKOUT_MINUTES));
            $user->lockUntil($until);
            $this->auditLogger->logSecurityEvent('account.locked', $user->getId()->toRfc4122(), [
                'failed_attempts' => $user->getFailedLoginCount(),
            ]);
        }

        $this->auditLogger->logAuth('login.failure', $user->getId()->toRfc4122());
        $this->em->flush();
    }
}
