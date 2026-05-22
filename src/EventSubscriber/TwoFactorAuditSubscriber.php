<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Service\Audit\AuditLogger;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class TwoFactorAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
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

        $this->auditLogger->logAuth('2fa.success', $user instanceof User ? $user->getId()->toRfc4122() : null, 'user', [
            'provider' => $token instanceof TwoFactorTokenInterface ? $token->getCurrentTwoFactorProvider() : null,
        ]);
    }

    public function onFailure(TwoFactorAuthenticationEvent $event): void
    {
        $token = $event->getToken();
        $user = $token->getUser();

        $this->auditLogger->logAuth('2fa.failure', $user instanceof User ? $user->getId()->toRfc4122() : null, 'user', [
            'provider' => $token instanceof TwoFactorTokenInterface ? $token->getCurrentTwoFactorProvider() : null,
        ]);
    }
}
