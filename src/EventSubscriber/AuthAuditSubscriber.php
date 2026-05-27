<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Service\Audit\AuditLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Logs all login success and failure events to the audit log.
 *
 * Login failures are always recorded regardless of role — you don't know at
 * failure time whether the target account is an admin. Brute-force detection
 * and anomaly alerting downstream can filter by the attempted username.
 *
 * ROLE_SUPER_ADMIN successes are tagged with actor_type='admin_user' so they
 * surface separately from regular user logins in audit queries.
 */
final class AuthAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            LoginFailureEvent::class => 'onLoginFailure',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getAuthenticatedToken()->getUser();

        if (!$user instanceof User) {
            return;
        }

        $isAdmin = \in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);

        $this->auditLogger->logAuth(
            'login.success',
            $user->getId()->toRfc4122(),
            $isAdmin ? 'admin_user' : 'user',
            ['ip' => $event->getRequest()->getClientIp()],
        );
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $request = $event->getRequest();

        $attempted = $request->request->getString('_username')
            ?: $request->request->getString('email')
            ?: null;

        $this->auditLogger->logAuth(
            'login.failure',
            null,
            'unknown',
            array_filter([
                'attempted_email' => $attempted,
                'reason' => $event->getException()->getMessageKey(),
                'ip' => $request->getClientIp(),
            ]),
        );
    }
}
