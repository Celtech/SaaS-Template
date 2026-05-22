<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\Entity\User;
use App\EventSubscriber\TwoFactorAuditSubscriber;
use App\Service\Audit\AuditLogger;
use App\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Symfony\Component\Security\Core\User\UserInterface;

final class TwoFactorAuditSubscriberTest extends UnitTestCase
{
    private AuditLogger&MockObject $auditLogger;
    private TwoFactorAuditSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->subscriber = new TwoFactorAuditSubscriber($this->auditLogger);
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
}
