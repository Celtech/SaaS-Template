<?php

declare(strict_types=1);

namespace App\Security\Enforcement;

use App\Entity\User;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

/**
 * Forces ROLE_SUPER_ADMIN users to enroll TOTP before accessing anything.
 *
 * Priority 10 — must be satisfied before step-up auth (20) is even relevant.
 *
 * Future org-level 2FA enforcement should be a separate class at priority 30
 * that targets regular users whose org has enforce_2fa enabled.
 */
final class AdminTwoFactorEnforcement implements SecurityEnforcementInterface
{
    public function __construct(private readonly RouterInterface $router)
    {
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function shouldEnforce(User $user, Request $request): bool
    {
        return \in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)
            && !$user->isTotpAuthenticationEnabled();
    }

    public function buildRedirectResponse(Request $request): RedirectResponse
    {
        return new RedirectResponse($this->router->generate('profile_2fa_setup'));
    }

    public function getExemptRoutes(): array
    {
        return [
            'profile_2fa_setup',
            'profile_2fa_enable',
            'profile_2fa_backup_codes',
        ];
    }
}
