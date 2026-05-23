<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Repository\BillingSettingsRepository;
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
 *   - No subscription / canceled (free tier disabled) → billing_plans
 *   - past_due / unpaid → billing_reactivate (update payment method)
 *
 * Routes excluded from the check: auth_*, billing_*, stripe_*, admin_*,
 * onboarding_*, 2fa*, _*, app_health.
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
        '_',
        'app_health',
    ];

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly SubscriptionRepository $subscriptionRepository,
        private readonly BillingSettingsRepository $billingSettingsRepository,
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
        $billingSettings = $this->billingSettingsRepository->getSettings();

        // No subscription at all
        if ($subscription === null) {
            if (!$billingSettings->isFreeTierEnabled()) {
                $event->setResponse(new RedirectResponse(
                    $this->urlGenerator->generate('billing_plans')
                ));
            }

            return;
        }

        // Subscription exists — check accessibility
        if ($subscription->isAccessible()) {
            return;
        }

        // past_due / unpaid → needs payment method update
        if ($subscription->isPastDue()) {
            if ($route !== 'billing_reactivate') {
                $event->setResponse(new RedirectResponse(
                    $this->urlGenerator->generate('billing_reactivate')
                ));
            }

            return;
        }

        // Canceled / other terminal status → pick a plan (unless free tier covers them)
        if ($billingSettings->isFreeTierEnabled() && $billingSettings->getFreeTierPlan() !== null) {
            return;
        }

        $event->setResponse(new RedirectResponse(
            $this->urlGenerator->generate('billing_plans')
        ));
    }
}
