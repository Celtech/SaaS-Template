<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Enum\NotificationType;
use App\Repository\UserSessionRepository;
use App\Service\Audit\AuditLogger;
use App\Service\Notification\NotificationDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
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
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly UserSessionRepository $sessions,
        private readonly NotificationDispatcher $notifications,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
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
        $ip = $event->getRequest()->getClientIp();

        $this->auditLogger->logAuth(
            'login.success',
            $user->getId()->toRfc4122(),
            $isAdmin ? 'admin_user' : 'user',
            ['ip' => $ip],
        );

        // Fires before SessionTrackingListener (kernel.request, priority 0) creates this
        // request's own UserSession row, so any prior row found here really is a past login.
        if (!$this->sessions->hasSessionFromIp($user, $ip)) {
            $this->notifications->dispatch(
                $user,
                NotificationType::SecurityNewLogin,
                'New login detected',
                \sprintf('A new login to your account was detected from %s.', $ip ?? 'an unknown location'),
                $this->urlGenerator->generate('profile_security', [], UrlGeneratorInterface::ABSOLUTE_URL),
            );
        }
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
