<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\Entity\Organization;
use App\Entity\User;
use App\EventSubscriber\OrgOnboardingSubscriber;
use App\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class OrgOnboardingSubscriberTest extends UnitTestCase
{
    /** @var TokenStorageInterface&Stub */
    private TokenStorageInterface $tokenStorage;

    /** @var UrlGeneratorInterface&Stub */
    private UrlGeneratorInterface $urlGenerator;

    private OrgOnboardingSubscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokenStorage = $this->createStub(TokenStorageInterface::class);
        $this->urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $this->urlGenerator->method('generate')->willReturn('/onboarding/org');
        $this->subscriber = new OrgOnboardingSubscriber($this->tokenStorage, $this->urlGenerator);
    }

    #[Test]
    public function itSubscribesToKernelRequest(): void
    {
        $events = OrgOnboardingSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(KernelEvents::REQUEST, $events);
    }

    #[Test]
    public function itDoesNothingForSubRequests(): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $event = new RequestEvent($kernel, new Request(), HttpKernelInterface::SUB_REQUEST);

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->expects($this->never())->method('getToken');
        $subscriber = new OrgOnboardingSubscriber($tokenStorage, $this->urlGenerator);

        $subscriber->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    #[Test]
    public function itDoesNothingWhenNoToken(): void
    {
        $this->tokenStorage->method('getToken')->willReturn(null);

        $event = $this->makeMainRequestEvent('/');
        $this->subscriber->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    #[Test]
    public function itDoesNothingForNonUserToken(): void
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(null);
        $this->tokenStorage->method('getToken')->willReturn($token);

        $event = $this->makeMainRequestEvent('/');
        $this->subscriber->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    #[Test]
    public function itDoesNothingWhenEmailNotVerified(): void
    {
        $user = new User('user@example.com', 'Test');

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $this->tokenStorage->method('getToken')->willReturn($token);

        $event = $this->makeMainRequestEvent('/');
        $this->subscriber->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    #[Test]
    public function itDoesNothingWhenUserAlreadyHasOrg(): void
    {
        $user = new User('user@example.com', 'Test');
        $user->markEmailVerified();
        $org = new Organization('My Workspace', $user);
        $user->setOrganization($org);

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $this->tokenStorage->method('getToken')->willReturn($token);

        $event = $this->makeMainRequestEvent('/');
        $this->subscriber->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    #[Test]
    public function itDoesNothingOnExcludedAuthRoutes(): void
    {
        $user = $this->makeVerifiedUserWithoutOrg();
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $this->tokenStorage->method('getToken')->willReturn($token);

        foreach (['auth_login', 'auth_register', '2fa_login', '_profiler', 'onboarding_org'] as $route) {
            $event = $this->makeMainRequestEvent('/', $route);
            $this->subscriber->onKernelRequest($event);
            $this->assertNull($event->getResponse(), "Expected no redirect for route: $route");
        }
    }

    #[Test]
    public function itRedirectsVerifiedUserWithoutOrgToOnboarding(): void
    {
        $user = $this->makeVerifiedUserWithoutOrg();
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $this->tokenStorage->method('getToken')->willReturn($token);

        $event = $this->makeMainRequestEvent('/', 'app_dashboard');
        $this->subscriber->onKernelRequest($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/onboarding/org', $response->getTargetUrl());
    }

    private function makeVerifiedUserWithoutOrg(): User
    {
        $user = new User('user@example.com', 'Test');
        $user->markEmailVerified();

        return $user;
    }

    private function makeMainRequestEvent(string $path, string $route = 'app_dashboard'): RequestEvent
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create($path);
        $request->attributes->set('_route', $route);
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        return $event;
    }
}
