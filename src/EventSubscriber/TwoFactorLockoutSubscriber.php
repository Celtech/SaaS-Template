<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Service\Auth\AccountLockoutService;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

/**
 * A 6-digit TOTP/email code or a backup code is brute-forceable (1M combinations
 * for a 6-digit code) once an attacker already has a valid password — scheb/2fa-bundle
 * doesn't rate-limit or lock out repeated failed code attempts on its own. This reuses
 * the same failed-attempt counter and lockout window as the primary password login
 * (AccountLockoutService / SecuritySettings), since a 2FA code is just another
 * authentication factor on the same account.
 */
final class TwoFactorLockoutSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly AccountLockoutService $lockoutService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TwoFactorAuthenticationEvents::ATTEMPT => 'onAttempt',
            TwoFactorAuthenticationEvents::FAILURE => 'onFailure',
            TwoFactorAuthenticationEvents::SUCCESS => 'onSuccess',
        ];
    }

    /**
     * Fires before the submitted code is checked — reject outright if the account
     * is already locked, so a locked-out attacker can't keep probing codes.
     */
    public function onAttempt(TwoFactorAuthenticationEvent $event): void
    {
        $user = $event->getToken()->getUser();
        if ($user instanceof User && $user->isLocked()) {
            throw new CustomUserMessageAuthenticationException('Your account has been temporarily locked due to too many failed attempts.');
        }
    }

    public function onFailure(TwoFactorAuthenticationEvent $event): void
    {
        $user = $event->getToken()->getUser();
        if ($user instanceof User) {
            $this->lockoutService->onFailure($user->getEmail());
        }
    }

    public function onSuccess(TwoFactorAuthenticationEvent $event): void
    {
        $user = $event->getToken()->getUser();
        if ($user instanceof User) {
            $this->lockoutService->onSuccess($user);
        }
    }
}
