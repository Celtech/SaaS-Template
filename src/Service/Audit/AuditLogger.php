<?php

declare(strict_types=1);

namespace App\Service\Audit;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Writes audit log entries directly via DBAL, bypassing the ORM transaction.
 * This ensures log entries are persisted even when the outer business transaction
 * is rolled back (e.g. failed login attempts, rejected operations).
 *
 * ---
 * AUDIT EVENT CATALOG
 * ---
 *
 * Events are namespaced by domain and follow the pattern:
 *   <namespace>.<subject>.<outcome>
 *
 * Use the typed helper methods (logAuth, logSecurityEvent, etc.) rather than
 * calling write() directly — they enforce the namespace prefix automatically.
 *
 * AUTH EVENTS  (logAuth)
 * Actions that occur during the authentication and registration flow.
 *
 *   auth.registered                   — new user account created
 *   auth.email.verified               — email address confirmed via token
 *   auth.login.success                — password credential accepted
 *   auth.login.failure                — credential rejected (context: reason, email)
 *   auth.logout                       — explicit sign-out
 *   auth.password_reset.requested     — password reset email dispatched
 *   auth.password_reset.completed     — password changed via reset token
 *   auth.2fa.success                  — 2FA challenge passed (context: provider)
 *   auth.2fa.failure                  — 2FA challenge failed (context: provider)
 *
 * SECURITY EVENTS  (logSecurityEvent)
 * Changes to a user's security posture or account state.
 *
 *   security.2fa.enabled              — TOTP two-factor authentication turned on
 *   security.2fa.disabled             — TOTP two-factor authentication turned off
 *   security.2fa.email.enabled        — email-code two-factor authentication turned on
 *   security.2fa.email.disabled       — email-code two-factor authentication turned off
 *   security.2fa.backup_codes.regenerated — backup codes replaced
 *   security.webauthn.key.added       — security key / passkey registered
 *   security.webauthn.key.removed     — security key / passkey removed
 *   security.account.locked           — account locked after repeated failures (context: failed_attempts)
 *   security.user.anonymized          — PII erased under GDPR/CCPA erasure request
 *
 * ADMIN EVENTS  (logAdminAction)
 * Actions taken by administrators via the admin backend.
 * Pattern: admin.<resource>.<verb>  e.g. admin.user.suspended
 *
 * BILLING EVENTS  (logBillingEvent)
 * Subscription and payment lifecycle events.
 * Pattern: billing.<resource>.<verb>  e.g. billing.subscription.created
 *
 * IMPERSONATION EVENTS  (logImpersonation)
 * Admin impersonation session lifecycle.
 * Pattern: impersonation.<verb>  e.g. impersonation.started, impersonation.ended
 */
class AuditLogger
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function logAuth(
        string $action,
        ?string $actorId = null,
        string $actorType = 'user',
        array $context = [],
    ): void {
        $this->write('auth.' . $action, $actorId, $actorType, null, null, null, empty($context) ? null : $context);
    }

    /**
     * @param array<string, mixed>|null $oldValue
     * @param array<string, mixed>|null $newValue
     */
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

    /**
     * @param array<string, mixed>|null $oldValue
     * @param array<string, mixed>|null $newValue
     */
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

    /**
     * @param array<string, mixed> $context
     */
    public function logSecurityEvent(
        string $action,
        ?string $actorId = null,
        array $context = [],
    ): void {
        $this->write('security.' . $action, $actorId, 'user', null, null, null, empty($context) ? null : $context);
    }

    /**
     * @param array<string, mixed>|null $oldValue
     * @param array<string, mixed>|null $newValue
     */
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
            'created_at' => new DateTimeImmutable()->format('Y-m-d H:i:s.u'),
        ]);
    }
}
