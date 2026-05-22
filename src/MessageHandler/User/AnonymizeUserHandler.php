<?php

declare(strict_types=1);

namespace App\MessageHandler\User;

use App\Entity\DataErasureRequest;
use App\Enum\UserStatus;
use App\Message\User\AnonymizeUserMessage;
use App\Repository\UserSessionRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;
use Throwable;

#[AsMessageHandler]
final class AnonymizeUserHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserSessionRepository $sessionRepository,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function __invoke(AnonymizeUserMessage $message): void
    {
        $request = $this->em->find(DataErasureRequest::class, Uuid::fromString($message->dataErasureRequestId));

        if ($request === null) {
            return;
        }

        $request->markProcessing();
        $this->em->flush();

        try {
            $user = $request->getUser();
            $userId = $user->getId()->toRfc4122();

            // Revoke all active sessions
            $this->sessionRepository->revokeAllForUser($user);

            // Anonymize PII — replace with deterministic placeholder derived from UUID
            // so audit log foreign key references remain valid but PII is gone
            $placeholder = 'deleted-' . substr($userId, 0, 8);
            $user->setEmail($placeholder . '@anonymized.invalid');
            $user->setName('Deleted User');
            $user->setAvatarUrl(null);
            $user->setStatus(UserStatus::Deleted);
            $user->disableTotp();

            $request->markCompleted();
            $this->em->flush();

            $this->auditLogger->logSecurityEvent('user.anonymized', $userId);
        } catch (Throwable $e) {
            $request->markFailed(['error' => $e->getMessage()]);
            $this->em->flush();

            throw $e;
        }
    }
}
