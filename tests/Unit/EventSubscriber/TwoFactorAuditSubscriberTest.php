<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\Entity\User;
use App\EventSubscriber\TwoFactorAuditSubscriber;
use App\Security\Enforcement\AdminStepUpEnforcement;
use App\Service\Audit\AuditLogger;
use App\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\User\UserInterface;

final class TwoFactorAuditSubscriberTest extends UnitTestCase
{
    private AuditLogger&MockObject $auditLogger;
    private RequestStack&MockObject $requestStack;
    private TwoFactorAuditSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->subscriber = new TwoFactorAuditSubscriber($this->auditLogger, $this->requestStack);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function itSubscribesToSuccessAndFailureEvents(): void
    {
        $events = TwoFactorAuditSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(TwoFactorAuthenticationEvents::SUCCESS, $events);
        $this->assertArrayHasKey(TwoFactorAuthenticationEvents::FAILURE, $events);
    }

    #[Test]
    public function onSuccessLogsAuthSuccessWithUserIdAndProvider(): void
    {
        $user = new User('success@example.com', 'Success User');

        $token = $this->createStub(TwoFactorTokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $token->method('getCurrentTwoFactorProvider')->willReturn('totp');

        $event = $this->createStub(TwoFactorAuthenticationEvent::class);
        $event->method('getToken')->willReturn($token);

        $this->auditLogger->expects($this->once())
            ->method('logAuth')
            ->with('2fa.success', $user->getId()->toRfc4122(), 'user', ['provider' => 'totp']);

        $this->subscriber->onSuccess($event);
    }

    #[Test]
    public function onFailureLogsAuthFailureWithUserIdAndProvider(): void
    {
        $user = new User('fail@example.com', 'Fail User');

        $token = $this->createStub(TwoFactorTokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $token->method('getCurrentTwoFactorProvider')->willReturn('email');

        $event = $this->createStub(TwoFactorAuthenticationEvent::class);
        $event->method('getToken')->willReturn($token);

        $this->auditLogger->expects($this->once())
            ->method('logAuth')
            ->with('2fa.failure', $user->getId()->toRfc4122(), 'user', ['provider' => 'email']);

        $this->subscriber->onFailure($event);
    }

    #[Test]
    public function onSuccessWithWebauthnProviderLogsCorrectProvider(): void
    {
        $user = new User('webauthn@example.com', 'Webauthn User');

        $token = $this->createStub(TwoFactorTokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $token->method('getCurrentTwoFactorProvider')->willReturn('webauthn');

        $event = $this->createStub(TwoFactorAuthenticationEvent::class);
        $event->method('getToken')->willReturn($token);

        $this->auditLogger->expects($this->once())
            ->method('logAuth')
            ->with('2fa.success', $user->getId()->toRfc4122(), 'user', ['provider' => 'webauthn']);

        $this->subscriber->onSuccess($event);
    }

    #[Test]
    public function onSuccessWithNonEntityUserPassesNullActorId(): void
    {
        $nonEntityUser = $this->createStub(UserInterface::class);

        $token = $this->createStub(TwoFactorTokenInterface::class);
        $token->method('getUser')->willReturn($nonEntityUser);
        $token->method('getCurrentTwoFactorProvider')->willReturn('totp');

        $event = $this->createStub(TwoFactorAuthenticationEvent::class);
        $event->method('getToken')->willReturn($token);

        $this->auditLogger->expects($this->once())
            ->method('logAuth')
            ->with('2fa.success', null, 'user', ['provider' => 'totp']);

        $this->subscriber->onSuccess($event);
    }

    #[Test]
    public function onSuccessForSuperAdminSetsStepUpSessionKeyAndLogsAdminAuth(): void
    {
        $user = new User('admin@example.com', 'Admin User');
        $user->setRoles(['ROLE_SUPER_ADMIN']);

        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);

        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $token = $this->createStub(TwoFactorTokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $token->method('getCurrentTwoFactorProvider')->willReturn('totp');

        $event = $this->createStub(TwoFactorAuthenticationEvent::class);
        $event->method('getToken')->willReturn($token);

        $this->auditLogger->expects($this->once())
            ->method('logAuth')
            ->with('2fa.success', $user->getId()->toRfc4122(), 'admin_user', ['provider' => 'totp']);

        $this->auditLogger->expects($this->once())
            ->method('logAdminAuth')
            ->with('stepup.confirmed', $user->getId()->toRfc4122(), ['provider' => 'totp']);

        $this->subscriber->onSuccess($event);

        $this->assertIsInt($session->get(AdminStepUpEnforcement::SESSION_KEY));
    }

    #[Test]
    public function onFailureForSuperAdminLogsWithAdminActorType(): void
    {
        $user = new User('admin@example.com', 'Admin User');
        $user->setRoles(['ROLE_SUPER_ADMIN']);

        $token = $this->createStub(TwoFactorTokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $token->method('getCurrentTwoFactorProvider')->willReturn('totp');

        $event = $this->createStub(TwoFactorAuthenticationEvent::class);
        $event->method('getToken')->willReturn($token);

        $this->auditLogger->expects($this->once())
            ->method('logAuth')
            ->with('2fa.failure', $user->getId()->toRfc4122(), 'admin_user', ['provider' => 'totp']);

        $this->subscriber->onFailure($event);
    }
}
