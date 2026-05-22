<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Entity\SecuritySettings;
use App\Entity\User;
use App\Repository\SecuritySettingsRepository;
use App\Repository\UserRepository;
use App\Service\Audit\AuditLogger;
use App\Service\Auth\AccountLockoutService;
use App\Tests\UnitTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;

final class AccountLockoutServiceTest extends UnitTestCase
{
    /** @var UserRepository&Stub */
    private UserRepository $userRepository;
    private EntityManagerInterface&MockObject $em;
    /** @var AuditLogger&Stub */
    private AuditLogger $auditLogger;
    /** @var SecuritySettingsRepository&Stub */
    private SecuritySettingsRepository $securitySettingsRepo;
    private AccountLockoutService $service;

    protected function setUp(): void
    {
        $this->userRepository = $this->createStub(UserRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->auditLogger = $this->createStub(AuditLogger::class);
        $this->securitySettingsRepo = $this->createStub(SecuritySettingsRepository::class);

        $this->service = new AccountLockoutService(
            $this->userRepository,
            $this->em,
            $this->auditLogger,
            $this->securitySettingsRepo,
        );
    }

    #[Test]
    public function onSuccessDoesNotFlushWhenNoFailedLogins(): void
    {
        $user = new User('clean@example.com', 'Clean User');

        $this->em->expects($this->never())->method('flush');

        $this->service->onSuccess($user);
    }

    #[Test]
    public function onSuccessResetsFailedCountAndFlushes(): void
    {
        $user = new User('recovering@example.com', 'Recovering User');
        $user->incrementFailedLoginCount();
        $user->incrementFailedLoginCount();

        $this->em->expects($this->once())->method('flush');

        $this->service->onSuccess($user);

        $this->assertSame(0, $user->getFailedLoginCount());
    }

    #[Test]
    public function onFailureWithUnknownEmailLogsWithEmailContextAndDoesNotFlush(): void
    {
        $this->userRepository->method('findByEmail')->willReturn(null);

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects($this->once())
            ->method('logAuth')
            ->with('login.failure', null, 'user', ['email' => 'ghost@example.com', 'reason' => 'unknown_email']);

        $this->em->expects($this->never())->method('flush');

        $service = new AccountLockoutService(
            $this->userRepository,
            $this->em,
            $auditLogger,
            $this->securitySettingsRepo,
        );
        $service->onFailure('ghost@example.com');
    }

    #[Test]
    public function onFailureBelowThresholdIncrementsCountWithoutLocking(): void
    {
        $user = new User('below@example.com', 'Below User');

        $this->userRepository->method('findByEmail')->willReturn($user);

        $settings = new SecuritySettings();
        $settings->setMaxFailedAttempts(5);
        $this->securitySettingsRepo->method('getSettings')->willReturn($settings);

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects($this->once())
            ->method('logAuth')
            ->with('login.failure', $user->getId()->toRfc4122());
        $auditLogger->expects($this->never())->method('logSecurityEvent');

        $this->em->expects($this->once())->method('flush');

        $service = new AccountLockoutService(
            $this->userRepository,
            $this->em,
            $auditLogger,
            $this->securitySettingsRepo,
        );
        $service->onFailure('below@example.com');

        $this->assertSame(1, $user->getFailedLoginCount());
        $this->assertFalse($user->isLocked());
    }

    #[Test]
    public function onFailureAtThresholdLocksUserAndLogsAccountLocked(): void
    {
        $user = new User('threshold@example.com', 'Threshold User');
        for ($i = 0; $i < 4; ++$i) {
            $user->incrementFailedLoginCount();
        }

        $this->userRepository->method('findByEmail')->willReturn($user);

        $settings = new SecuritySettings();
        $settings->setMaxFailedAttempts(5);
        $settings->setLockoutMinutes(30);
        $this->securitySettingsRepo->method('getSettings')->willReturn($settings);

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects($this->once())
            ->method('logSecurityEvent')
            ->with('account.locked', $user->getId()->toRfc4122(), ['failed_attempts' => 5]);
        $auditLogger->expects($this->once())
            ->method('logAuth')
            ->with('login.failure', $user->getId()->toRfc4122());

        $this->em->expects($this->once())->method('flush');

        $service = new AccountLockoutService(
            $this->userRepository,
            $this->em,
            $auditLogger,
            $this->securitySettingsRepo,
        );
        $service->onFailure('threshold@example.com');

        $this->assertTrue($user->isLocked());
        $this->assertSame(5, $user->getFailedLoginCount());
    }

    #[Test]
    public function onFailureUsesDbThresholdsInsteadOfHardcodedDefaults(): void
    {
        $user = new User('custom@example.com', 'Custom User');
        $user->incrementFailedLoginCount();
        $user->incrementFailedLoginCount();

        $this->userRepository->method('findByEmail')->willReturn($user);

        $settings = new SecuritySettings();
        $settings->setMaxFailedAttempts(3);
        $settings->setLockoutMinutes(60);
        $this->securitySettingsRepo->method('getSettings')->willReturn($settings);

        $this->em->expects($this->once())->method('flush');

        $this->service = new AccountLockoutService(
            $this->userRepository,
            $this->em,
            $this->auditLogger,
            $this->securitySettingsRepo,
        );
        $this->service->onFailure('custom@example.com');

        $this->assertTrue($user->isLocked(), 'Must lock at custom threshold of 3, not the default 5');
    }
}
