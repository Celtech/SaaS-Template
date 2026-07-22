<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\Entity\Organization;
use App\Entity\Plan;
use App\Entity\Subscription;
use App\Entity\SubscriptionStatus;
use App\Entity\User;
use App\EventSubscriber\SubscriptionAccessSubscriber;
use App\Repository\SubscriptionRepository;
use App\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class SubscriptionAccessSubscriberTest extends UnitTestCase
{
    /** @var TokenStorageInterface&Stub */
    private TokenStorageInterface $tokenStorage;

    /** @var SubscriptionRepository&Stub */
    private SubscriptionRepository $subscriptionRepository;

    /** @var UrlGeneratorInterface&Stub */
    private UrlGeneratorInterface $urlGenerator;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokenStorage = $this->createStub(TokenStorageInterface::class);
        $this->subscriptionRepository = $this->createStub(SubscriptionRepository::class);
        $this->urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $this->urlGenerator->method('generate')->willReturn('/billing/reactivate');

        $this->user = new User('user@example.com', 'Test');
        $org = new Organization('Workspace', $this->user);
        $this->user->setOrganization($org);

        $plan = new Plan('basic', 'Basic');
        $subscription = new Subscription($org, $plan, SubscriptionStatus::Unpaid);
        $this->subscriptionRepository->method('findForOrg')->willReturn($subscription);

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($this->user);
        $this->tokenStorage->method('getToken')->willReturn($token);
    }

    /**
     * Regression test for #65: a ROLE_SUPER_ADMIN without TOTP whose org's subscription
     * is unpaid was redirected to profile_2fa_setup by AdminTwoFactorEnforcement, then
     * immediately bounced to billing_reactivate by this subscriber (which didn't
     * recognize profile_2fa_setup/profile_webauthn_register as exempt), then back — forever.
     */
    #[Test]
    public function itDoesNothingOnProfileTwoFactorAndWebauthnSetupRoutesEvenWithUnpaidSubscription(): void
    {
        $subscriber = $this->makeSubscriber(billingEnabled: true);

        foreach (['profile_2fa_setup', 'profile_2fa_enable', 'profile_2fa_backup_codes', 'profile_webauthn_register'] as $route) {
            $event = $this->makeMainRequestEvent($route);
            $subscriber->onKernelRequest($event);
            $this->assertNull($event->getResponse(), "Expected no redirect for route: $route");
        }
    }

    #[Test]
    public function itRedirectsToBillingReactivateForUnpaidSubscriptionOnNonExcludedRoute(): void
    {
        $subscriber = $this->makeSubscriber(billingEnabled: true);

        $event = $this->makeMainRequestEvent('app_dashboard');
        $subscriber->onKernelRequest($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/billing/reactivate', $response->getTargetUrl());
    }

    #[Test]
    public function itDoesNothingWhenBillingDisabled(): void
    {
        $subscriber = $this->makeSubscriber(billingEnabled: false);

        $event = $this->makeMainRequestEvent('app_dashboard');
        $subscriber->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    private function makeSubscriber(bool $billingEnabled): SubscriptionAccessSubscriber
    {
        return new SubscriptionAccessSubscriber(
            $this->tokenStorage,
            $this->subscriptionRepository,
            $this->urlGenerator,
            $billingEnabled,
        );
    }

    private function makeMainRequestEvent(string $route): RequestEvent
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/');
        $request->attributes->set('_route', $route);

        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
