<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Repository\SubscriptionRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Blocks app access when an org's subscription is not in an accessible state.
 *
 * Runs after OrgOnboardingSubscriber (priority 4 vs 5) so users without orgs
 * are redirected to onboarding first, before subscription checks happen.
 *
 * Redirect targets:
 *   - No subscription → onboarding_org (shouldn't happen; fixes broken state)
 *   - past_due / unpaid / canceled / terminal → billing_reactivate
 *
 * Routes excluded from the check: auth_*, billing_*, stripe_*, admin_*,
 * onboarding_*, 2fa*, profile_2fa*, profile_webauthn*, _*, app_health.
 *
 * The profile_2fa and profile_webauthn prefixes must stay excluded alongside the
 * login-time '2fa' challenge routes — otherwise a ROLE_SUPER_ADMIN without TOTP and
 * without a paid subscription gets bounced here by AdminTwoFactorEnforcement, then
 * straight back there by this subscriber, forever (see issue #65).
 */
final class SubscriptionAccessSubscriber implements EventSubscriberInterface
{
    private const EXCLUDED_ROUTE_PREFIXES = [
        'auth_',
        'billing_',
        'stripe_',
        'admin_',
        'onboarding_',
        '2fa',
        'profile_2fa',
        'profile_webauthn',
        '_',
        'app_health',
    ];

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly SubscriptionRepository $subscriptionRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire(param: 'billing.enabled')]
        private readonly bool $billingEnabled,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 4]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$this->billingEnabled) {
            return;
        }

        if (!$event->isMainRequest()) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();

        if (!$user instanceof User) {
            return;
        }

        $org = $user->getOrganization();

        if ($org === null) {
            return;
        }

        $route = (string) $event->getRequest()->attributes->get('_route', '');

        foreach (self::EXCLUDED_ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return;
            }
        }

        $subscription = $this->subscriptionRepository->findForOrg($org);

        // No subscription — onboarding should have assigned one; send back to fix it
        if ($subscription === null) {
            $event->setResponse(new RedirectResponse(
                $this->urlGenerator->generate('onboarding_org')
            ));

            return;
        }

        // Subscription exists — check accessibility
        if ($subscription->isAccessible()) {
            return;
        }

        // past_due / unpaid / canceled / terminal → manage via reactivate page
        if ($route !== 'billing_reactivate') {
            $event->setResponse(new RedirectResponse(
                $this->urlGenerator->generate('billing_reactivate')
            ));
        }
    }
}
