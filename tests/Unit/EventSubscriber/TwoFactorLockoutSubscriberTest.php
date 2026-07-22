<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\Entity\User;
use App\EventSubscriber\TwoFactorLockoutSubscriber;
use App\Service\Auth\AccountLockoutService;
use App\Tests\UnitTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;

final class TwoFactorLockoutSubscriberTest extends UnitTestCase
{
    private AccountLockoutService&MockObject $lockoutService;
    private TwoFactorLockoutSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->lockoutService = $this->createMock(AccountLockoutService::class);
        $this->subscriber = new TwoFactorLockoutSubscriber($this->lockoutService);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function itSubscribesToAttemptFailureAndSuccessEvents(): void
    {
        $events = TwoFactorLockoutSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(TwoFactorAuthenticationEvents::ATTEMPT, $events);
        $this->assertArrayHasKey(TwoFactorAuthenticationEvents::FAILURE, $events);
        $this->assertArrayHasKey(TwoFactorAuthenticationEvents::SUCCESS, $events);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function onAttemptDoesNothingWhenUserIsNotLocked(): void
    {
        $user = new User('not-locked@example.com', 'Test');

        $token = $this->createStub(TwoFactorTokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $event = $this->createStub(TwoFactorAuthenticationEvent::class);
        $event->method('getToken')->willReturn($token);

        $this->subscriber->onAttempt($event);

        $this->addToAssertionCount(1); // no exception thrown
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function onAttemptThrowsWhenUserIsLocked(): void
    {
        $user = new User('locked@example.com', 'Test');
        $user->lockUntil(new DateTimeImmutable('+15 minutes'));

        $token = $this->createStub(TwoFactorTokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $event = $this->createStub(TwoFactorAuthenticationEvent::class);
        $event->method('getToken')->willReturn($token);

        $this->expectException(CustomUserMessageAuthenticationException::class);

        $this->subscriber->onAttempt($event);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function onAttemptWithNonEntityUserDoesNothing(): void
    {
        $nonEntityUser = $this->createStub(UserInterface::class);

        $token = $this->createStub(TwoFactorTokenInterface::class);
        $token->method('getUser')->willReturn($nonEntityUser);

        $event = $this->createStub(TwoFactorAuthenticationEvent::class);
        $event->method('getToken')->willReturn($token);

        $this->subscriber->onAttempt($event);

        $this->addToAssertionCount(1); // no exception thrown
    }

    #[Test]
    public function onFailureIncrementsLockoutCounterForTheUsersEmail(): void
    {
        $user = new User('failure@example.com', 'Test');

        $token = $this->createStub(TwoFactorTokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $event = $this->createStub(TwoFactorAuthenticationEvent::class);
        $event->method('getToken')->willReturn($token);

        $this->lockoutService->expects($this->once())
            ->method('onFailure')
            ->with('failure@example.com');

        $this->subscriber->onFailure($event);
    }

    #[Test]
    public function onSuccessResetsLockoutCounterForTheUser(): void
    {
        $user = new User('success@example.com', 'Test');

        $token = $this->createStub(TwoFactorTokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $event = $this->createStub(TwoFactorAuthenticationEvent::class);
        $event->method('getToken')->willReturn($token);

        $this->lockoutService->expects($this->once())
            ->method('onSuccess')
            ->with($user);

        $this->subscriber->onSuccess($event);
    }
}
