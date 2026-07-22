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
 * actor_type='admin_user' is set automatically for ROLE_SUPER_ADMIN accounts.
 *
 *   auth.registered                   — new user account created
 *   auth.email.verified               — email address confirmed via token
 *   auth.login.success                — password credential accepted (context: ip)
 *   auth.login.failure                — credential rejected (context: attempted_email, reason, ip)
 *   auth.logout                       — explicit sign-out
 *   auth.password_reset.requested     — password reset email dispatched
 *   auth.password_reset.completed     — password changed via reset token
 *   auth.2fa.success                  — 2FA challenge passed (context: provider)
 *   auth.2fa.failure                  — 2FA challenge failed (context: provider)
 *
 * ADMIN AUTH EVENTS  (logAdminAuth)
 * Auth and session events specific to ROLE_SUPER_ADMIN accounts.
 *
 *   admin.auth.stepup.confirmed       — re-authentication for admin panel accepted
 *   admin.auth.stepup.failed          — re-authentication for admin panel rejected
 *   admin.auth.session.timeout        — admin session expired after 15 min idle
 *   admin.auth.panel.first_access     — first admin panel route hit in this session
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
 *   security.password.changed         — password changed from the profile security page
 *   security.email.changed            — login email changed from the profile page (context: old_email, new_email)
 *   security.session.revoked          — a single session was revoked from the profile page
 *   security.session.revoked_all      — all other sessions were revoked from the profile page
 *
 * ADMIN EVENTS  (logAdminAction)
 * Actions taken by administrators via the admin backend.
 * Pattern: admin.<resource>.<verb>  e.g. admin.user.suspended
 *
 * ORG EVENTS  (logOrgEvent)
 * Organization self-service management actions taken by an org member
 * (as opposed to ADMIN EVENTS, which are super-admin backend actions).
 *
 *   org.settings.updated              — organization name or settings changed
 *   org.member.removed                — a member was removed from the organization
 *   org.member.role_changed           — a member's org role was changed
 *   org.invitation.sent               — an invitation to join the organization was sent
 *   org.invitation.revoked            — a pending invitation was revoked
 *
 * BILLING EVENTS  (logBillingEvent)
 * Subscription and payment lifecycle events.
 * Pattern: billing.<resource>.<verb>  e.g. billing.subscription.created
 *
 * IMPERSONATION EVENTS  (logImpersonation)
 * Admin impersonation session lifecycle.
 * Pattern: impersonation.<verb>  e.g. impersonation.started, impersonation.ended
 *
 * OAUTH EVENTS  (logOAuthEvent)
 * Developer-area OAuth client management and end-user consent decisions.
 *
 *   oauth.client.created              — OAuth application registered (context: name, grants, scopes)
 *   oauth.client.secret_regenerated    — client secret rotated
 *   oauth.client.deleted               — OAuth application removed
 *   oauth.authorization.granted        — user approved a client's consent request (context: scopes)
 *   oauth.authorization.denied         — user rejected a client's consent request
 *   oauth.device_authorization.granted — user approved a device authorization request (context: scopes)
 *   oauth.device_authorization.denied  — user rejected a device authorization request
 *
 * WEBHOOK EVENTS  (logWebhookEvent)
 * Outgoing webhook endpoint management in the developer area.
 *
 *   webhook.endpoint.created            — endpoint registered (context: url, events)
 *   webhook.endpoint.updated            — url, events, or active state changed
 *   webhook.endpoint.secret_regenerated — signing secret rotated
 *   webhook.endpoint.deleted            — endpoint removed
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

    /**
     * @param array<string, mixed> $context
     */
    public function logAdminAuth(
        string $action,
        string $actorId,
        array $context = [],
    ): void {
        $this->write('admin.auth.' . $action, $actorId, 'admin_user', null, null, null, empty($context) ? null : $context);
    }

    public function logImpersonation(
        string $action,
        string $actorId,
        string $targetUserId,
        ?string $impersonationSessionId = null,
        ?string $reason = null,
    ): void {
        $this->write(
            'impersonation.' . $action,
            $actorId,
            'admin_user',
            $targetUserId,
            'user',
            null,
            $reason !== null ? ['reason' => $reason] : null,
            $impersonationSessionId,
        );
    }

    /**
     * @param array<string, mixed>|null $oldValue
     * @param array<string, mixed>|null $newValue
     */
    public function logOrgEvent(
        string $action,
        string $subjectId,
        string $subjectType,
        ?array $oldValue = null,
        ?array $newValue = null,
        ?string $actorId = null,
        string $actorType = 'user',
    ): void {
        $this->write('org.' . $action, $actorId, $actorType, $subjectId, $subjectType, $oldValue, $newValue);
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
    public function logOAuthEvent(
        string $action,
        string $subjectId,
        string $subjectType,
        ?array $oldValue = null,
        ?array $newValue = null,
        ?string $actorId = null,
        string $actorType = 'user',
    ): void {
        $this->write('oauth.' . $action, $actorId, $actorType, $subjectId, $subjectType, $oldValue, $newValue);
    }

    /**
     * @param array<string, mixed>|null $oldValue
     * @param array<string, mixed>|null $newValue
     */
    public function logWebhookEvent(
        string $action,
        string $subjectId,
        ?array $oldValue = null,
        ?array $newValue = null,
        ?string $actorId = null,
    ): void {
        $this->write('webhook.' . $action, $actorId, 'user', $subjectId, 'webhook_endpoint', $oldValue, $newValue);
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

        // Auto-attach impersonation session to every log entry written during an impersonation session.
        if ($impersonationSessionId === null && $request !== null && $request->hasSession()) {
            $sid = $request->getSession()->get('_impersonation_session_id');
            if (\is_string($sid)) {
                $impersonationSessionId = $sid;
            }
        }

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
