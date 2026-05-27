<?php

declare(strict_types=1);

namespace App\Security\Enforcement;

use App\Entity\User;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

/**
 * Requires ROLE_SUPER_ADMIN users to re-confirm their password before
 * accessing any /admin/* route. The confirmation is valid for 15 minutes
 * (matching the idle session timeout).
 *
 * Priority 20 — runs after 2FA enrollment (10) is satisfied.
 */
final class AdminStepUpEnforcement implements SecurityEnforcementInterface
{
    private const TTL_SECONDS = 900;
    public const SESSION_KEY = '_admin_stepup_confirmed_at';
    public const RETURN_URL_KEY = '_admin_stepup_return_url';

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
        $request->getSession()->set(self::RETURN_URL_KEY, $request->getUri());

        return new RedirectResponse($this->router->generate('admin_stepup_confirm'));
    }

    public function getExemptRoutes(): array
    {
        return ['admin_stepup_confirm'];
    }
}
