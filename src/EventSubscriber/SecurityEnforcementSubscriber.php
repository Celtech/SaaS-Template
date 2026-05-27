<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Security\Enforcement\SecurityEnforcementInterface;
use App\Service\Audit\AuditLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Orchestrates all SecurityEnforcementInterface rules on every authenticated request.
 *
 * Evaluation order:
 *   1. Skip sub-requests, unauthenticated users, and globally exempt routes.
 *   2. Idle session timeout for ROLE_SUPER_ADMIN (hard logout, not a redirect gate).
 *   3. Enforcement chain — rules sorted by priority, first match wins.
 *
 * Loop safety: every route declared in any enforcement's getExemptRoutes() is
 * exempt from ALL enforcement. A user completing one gate cannot be intercepted
 * by another gate on the same page.
 */
final class SecurityEnforcementSubscriber implements EventSubscriberInterface
{
    private const IDLE_TIMEOUT_SECONDS = 900;
    private const LAST_ACTIVITY_KEY = '_super_admin_last_activity';
    private const ADMIN_PANEL_LOGGED_KEY = '_admin_panel_access_logged';

    /** @var SecurityEnforcementInterface[] */
    private array $sorted = [];

    /** @var string[] */
    private readonly array $globalExemptRoutes;

    /**
     * @param iterable<SecurityEnforcementInterface> $enforcements
     */
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly RouterInterface $router,
        private readonly AuditLogger $auditLogger,
        private readonly iterable $enforcements,
    ) {
        $this->globalExemptRoutes = [
            // Auth flows
            'auth_login', 'auth_logout', 'auth_register',
            'auth_forgot_password', 'auth_reset_password',
            'auth_verify_email', 'auth_verify_email_notice', 'auth_resend_verification',
            // scheb 2FA challenge (in-progress authentication, not yet logged in)
            '2fa_login', '2fa_login_check', '2fa_backup_code',
            '2fa_resend_code', '2fa_select_provider',
        ];
    }

    public static function getSubscribedEvents(): array
    {
        // Priority 7: after routing (8) resolves the route name, before controllers.
        return [KernelEvents::REQUEST => ['onKernelRequest', 7]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $user = $this->getAuthenticatedUser();

        if ($user === null) {
            return;
        }

        $route = $request->attributes->getString('_route');

        // Idle timeout runs on ALL routes — a timed-out admin is logged out
        // regardless of which page they are on, including enforcement target pages.
        if ($this->handleIdleTimeout($user, $request, $event)) {
            return;
        }

        // Build the full exempt set from all registered enforcements.
        $exemptRoutes = array_merge($this->globalExemptRoutes, $this->collectExemptRoutes());

        if (\in_array($route, $exemptRoutes, true)) {
            return;
        }

        // Log first admin panel access per session.
        if (str_starts_with($request->getPathInfo(), '/admin')) {
            $this->maybeLogAdminPanelAccess($user, $request);
        }

        // Run enforcement chain — lowest priority number wins.
        foreach ($this->getSortedEnforcements() as $enforcement) {
            if (!$enforcement->shouldEnforce($user, $request)) {
                continue;
            }

            $event->setResponse($enforcement->buildRedirectResponse($request));

            return;
        }
    }

    private function handleIdleTimeout(User $user, Request $request, RequestEvent $event): bool
    {
        if (!\in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return false;
        }

        $session = $request->getSession();
        $lastActivity = $session->get(self::LAST_ACTIVITY_KEY);

        if (\is_int($lastActivity) && (time() - $lastActivity) > self::IDLE_TIMEOUT_SECONDS) {
            $userId = $user->getId()->toRfc4122();

            $this->tokenStorage->setToken(null);
            $session->invalidate();

            $flashBag = $session instanceof Session ? $session->getFlashBag() : null;
            if ($flashBag instanceof FlashBagInterface) {
                $flashBag->add('warning', 'Your admin session expired after 15 minutes of inactivity.');
            }

            $this->auditLogger->logAdminAuth('session.timeout', $userId);

            $event->setResponse(new RedirectResponse($this->router->generate('auth_login')));

            return true;
        }

        $session->set(self::LAST_ACTIVITY_KEY, time());

        return false;
    }

    private function maybeLogAdminPanelAccess(User $user, Request $request): void
    {
        $session = $request->getSession();

        if ($session->get(self::ADMIN_PANEL_LOGGED_KEY) === true) {
            return;
        }

        $session->set(self::ADMIN_PANEL_LOGGED_KEY, true);

        $this->auditLogger->logAdminAuth(
            'panel.first_access',
            $user->getId()->toRfc4122(),
            ['route' => $request->attributes->getString('_route')],
        );
    }

    private function getAuthenticatedUser(): ?User
    {
        $token = $this->tokenStorage->getToken();
        if ($token === null) {
            return null;
        }

        $user = $token->getUser();

        return $user instanceof User ? $user : null;
    }

    /** @return string[] */
    private function collectExemptRoutes(): array
    {
        $routes = [];
        foreach ($this->enforcements as $enforcement) {
            foreach ($enforcement->getExemptRoutes() as $route) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    /** @return SecurityEnforcementInterface[] */
    private function getSortedEnforcements(): array
    {
        if ($this->sorted === []) {
            $enforcements = [];
            foreach ($this->enforcements as $enforcement) {
                $enforcements[] = $enforcement;
            }
            usort($enforcements, static fn ($a, $b) => $a->getPriority() <=> $b->getPriority());
            $this->sorted = $enforcements;
        }

        return $this->sorted;
    }
}
