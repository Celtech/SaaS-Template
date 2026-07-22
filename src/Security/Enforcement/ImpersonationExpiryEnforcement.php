<?php

declare(strict_types=1);

namespace App\Security\Enforcement;

use App\Entity\User;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\Session;

/**
 * Forcibly ends an admin impersonation session 60 minutes after it started,
 * regardless of activity — an impersonation session is elevated access to
 * another user's account and must not be left open indefinitely.
 *
 * Detection is session-based (the '_impersonation' key set by
 * ImpersonationController) rather than token-based, so it fires even though
 * $user here is the impersonated target, not the admin.
 *
 * Ending is done via Symfony's switch_user "_exit" mechanism (a redirect
 * with ?_switch_user=_exit), which restores the admin's original token —
 * not a full re-authentication, so requiresFullReauthentication() is false.
 * ImpersonationSubscriber::onSwitchUser() handles the actual session
 * cleanup and audit log entry once that redirect is followed.
 */
final class ImpersonationExpiryEnforcement implements SecurityEnforcementInterface
{
    private const TTL_SECONDS = 3600;
    private const SESSION_KEY = '_impersonation';

    public function getPriority(): int
    {
        return 15;
    }

    public function shouldEnforce(User $user, Request $request): bool
    {
        $data = $request->getSession()->get(self::SESSION_KEY);

        if (!\is_array($data) || !isset($data['started_at'])) {
            return false;
        }

        return (time() - (int) $data['started_at']) > self::TTL_SECONDS;
    }

    public function buildRedirectResponse(Request $request): RedirectResponse
    {
        $session = $request->getSession();

        /** @var array<string, mixed> $data */
        $data = $session->get(self::SESSION_KEY, []);
        $returnUrl = \is_string($data['return_url'] ?? null) ? $data['return_url'] : '/';

        // Flagged so ImpersonationSubscriber's exit handler can record why
        // the session ended, distinct from an admin manually exiting.
        $data['expired'] = true;
        $session->set(self::SESSION_KEY, $data);

        $flashBag = $session instanceof Session ? $session->getFlashBag() : null;
        if ($flashBag instanceof FlashBagInterface) {
            $flashBag->add('warning', 'Impersonation session expired after 60 minutes and was ended automatically.');
        }

        $separator = str_contains($returnUrl, '?') ? '&' : '?';

        return new RedirectResponse($returnUrl . $separator . '_switch_user=_exit');
    }

    public function getExemptRoutes(): array
    {
        return [];
    }

    public function requiresFullReauthentication(): bool
    {
        return false;
    }
}
