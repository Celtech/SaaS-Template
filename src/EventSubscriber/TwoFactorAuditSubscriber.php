<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Security\Enforcement\AdminStepUpEnforcement;
use App\Service\Audit\AuditLogger;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class TwoFactorAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly RequestStack $requestStack,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TwoFactorAuthenticationEvents::SUCCESS => 'onSuccess',
            TwoFactorAuthenticationEvents::FAILURE => 'onFailure',
        ];
    }

    public function onSuccess(TwoFactorAuthenticationEvent $event): void
    {
        $token = $event->getToken();
        $user = $token->getUser();
        $isAdmin = $user instanceof User && \in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);

        $this->auditLogger->logAuth(
            '2fa.success',
            $user instanceof User ? $user->getId()->toRfc4122() : null,
            $isAdmin ? 'admin_user' : 'user',
            ['provider' => $token instanceof TwoFactorTokenInterface ? $token->getCurrentTwoFactorProvider() : null],
        );

        // Completing 2FA satisfies the admin step-up requirement.
        if ($isAdmin) {
            $request = $this->requestStack->getCurrentRequest();
            $request?->getSession()->set(AdminStepUpEnforcement::SESSION_KEY, time());

            $this->auditLogger->logAdminAuth('stepup.confirmed', $user->getId()->toRfc4122(), [
                'provider' => $token instanceof TwoFactorTokenInterface ? $token->getCurrentTwoFactorProvider() : null,
            ]);
        }
    }

    public function onFailure(TwoFactorAuthenticationEvent $event): void
    {
        $token = $event->getToken();
        $user = $token->getUser();
        $isAdmin = $user instanceof User && \in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);

        $this->auditLogger->logAuth(
            '2fa.failure',
            $user instanceof User ? $user->getId()->toRfc4122() : null,
            $isAdmin ? 'admin_user' : 'user',
            ['provider' => $token instanceof TwoFactorTokenInterface ? $token->getCurrentTwoFactorProvider() : null],
        );
    }
}
