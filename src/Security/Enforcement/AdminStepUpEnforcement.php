<?php

declare(strict_types=1);

namespace App\Security\Enforcement;

use App\Entity\User;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

/**
 * Requires ROLE_SUPER_ADMIN users to fully re-authenticate (password + 2FA)
 * before accessing any /admin/* route. Redirects to the standard login page
 * so all configured 2FA methods are available.
 *
 * After successful re-authentication, TwoFactorAuditSubscriber sets the
 * confirmation session key and scheb redirects back to the intended URL.
 *
 * Priority 20 — runs after 2FA enrollment (10) is satisfied.
 */
final class AdminStepUpEnforcement implements SecurityEnforcementInterface
{
    private const TTL_SECONDS = 900;
    public const SESSION_KEY = '_admin_stepup_confirmed_at';

    // Symfony's target path session key for the 'main' firewall.
    private const TARGET_PATH_KEY = '_security.main.target_path';

    public function __construct(private readonly RouterInterface $router)
    {
    }

    public function getPriority(): int
    {
        return 20;
    }

    public function shouldEnforce(User $user, Request $request): bool
    {
        if (!\in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return false;
        }

        if (!str_starts_with($request->getPathInfo(), '/admin')) {
            return false;
        }

        $confirmedAt = $request->getSession()->get(self::SESSION_KEY);

        if (!\is_int($confirmedAt)) {
            return true;
        }

        return (time() - $confirmedAt) > self::TTL_SECONDS;
    }

    public function buildRedirectResponse(Request $request): RedirectResponse
    {
        // Store the intended destination so scheb redirects back after 2FA completes.
        $request->getSession()->set(self::TARGET_PATH_KEY, $request->getUri());

        return new RedirectResponse($this->router->generate('auth_login'));
    }

    public function getExemptRoutes(): array
    {
        return [];
    }

    public function requiresFullReauthentication(): bool
    {
        return true;
    }
}
