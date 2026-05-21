<?php

declare(strict_types=1);

namespace App\Service\Audit;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Writes audit log entries directly via DBAL, bypassing the ORM transaction.
 * This ensures log entries are persisted even when the outer business transaction
 * is rolled back (e.g. failed login attempts, rejected operations).
 */
class AuditLogger
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function logAuth(
        string $action,
        ?string $actorId = null,
        string $actorType = 'user',
        array $context = [],
    ): void {
        $this->write('auth.' . $action, $actorId, $actorType, null, null, null, empty($context) ? null : $context);
    }

    public function logAdminAction(
        string $action,
        string $actorId,
        string $subjectId,
        string $subjectType,
        ?array $oldValue = null,
        ?array $newValue = null,
    ): void {
        $this->write('admin.' . $action, $actorId, 'admin_user', $subjectId, $subjectType, $oldValue, $newValue);
    }

    public function logImpersonation(
        string $action,
        string $actorId,
        string $targetUserId,
        ?string $impersonationSessionId = null,
    ): void {
        $this->write(
            'impersonation.' . $action,
            $actorId,
            'admin_user',
            $targetUserId,
            'user',
            null,
            null,
            $impersonationSessionId,
        );
    }

    public function logBillingEvent(
        string $action,
        string $subjectId,
        string $subjectType,
        ?array $oldValue = null,
        ?array $newValue = null,
        ?string $actorId = null,
        string $actorType = 'user',
    ): void {
        $this->write('billing.' . $action, $actorId, $actorType, $subjectId, $subjectType, $oldValue, $newValue);
    }

    public function logSecurityEvent(
        string $action,
        ?string $actorId = null,
        array $context = [],
    ): void {
        $this->write('security.' . $action, $actorId, 'user', null, null, null, empty($context) ? null : $context);
    }

    private function write(
        string $action,
        ?string $actorId,
        ?string $actorType,
        ?string $subjectId,
        ?string $subjectType,
        ?array $oldValue,
        ?array $newValue,
        ?string $impersonationSessionId = null,
    ): void {
        $request = $this->requestStack->getCurrentRequest();

        $this->connection->insert('audit_log', [
            'id' => Uuid::v7()->toRfc4122(),
            'actor_id' => $actorId,
            'actor_type' => $actorType,
            'action' => $action,
            'subject_id' => $subjectId,
            'subject_type' => $subjectType,
            'old_value' => $oldValue !== null ? json_encode($oldValue, \JSON_THROW_ON_ERROR) : null,
            'new_value' => $newValue !== null ? json_encode($newValue, \JSON_THROW_ON_ERROR) : null,
            'ip_address' => $request?->getClientIp(),
            'user_agent' => $request?->headers->get('User-Agent'),
            'impersonation_session_id' => $impersonationSessionId,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        ]);
    }
}
