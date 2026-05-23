<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class OrgOnboardingSubscriber implements EventSubscriberInterface
{
    private const EXCLUDED_ROUTE_PREFIXES = ['auth_', '2fa', '_', 'onboarding_', 'app_health'];

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 5]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();

        if (!$user instanceof User) {
            return;
        }

        if (!$user->isEmailVerified()) {
            return;
        }

        if ($user->getOrganization() !== null) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route', '');
        foreach (self::EXCLUDED_ROUTE_PREFIXES as $prefix) {
            if (str_starts_with((string) $route, $prefix)) {
                return;
            }
        }

        $event->setResponse(new RedirectResponse(
            $this->urlGenerator->generate('onboarding_org')
        ));
    }
}
