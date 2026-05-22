<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler\User;

use App\Entity\DataErasureRequest;
use App\Entity\User;
use App\Enum\DataErasureStatus;
use App\Message\User\AnonymizeUserMessage;
use App\MessageHandler\User\AnonymizeUserHandler;
use App\Repository\UserSessionRepository;
use App\Service\Audit\AuditLogger;
use App\Tests\UnitTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

final class AnonymizeUserHandlerTest extends UnitTestCase
{
    private EntityManagerInterface&MockObject $em;
    private UserSessionRepository&MockObject $sessionRepository;
    /** @var AuditLogger&Stub */
    private AuditLogger $auditLogger;
    private AnonymizeUserHandler $handler;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->sessionRepository = $this->createMock(UserSessionRepository::class);
        $this->auditLogger = $this->createStub(AuditLogger::class);

        $this->handler = new AnonymizeUserHandler(
            $this->em,
            $this->sessionRepository,
            $this->auditLogger,
        );
    }

    #[Test]
    public function itDoesNothingWhenRequestNotFound(): void
    {
        $this->em->expects($this->once())
            ->method('find')
            ->willReturn(null);

        $this->em->expects($this->never())->method('flush');
        $this->sessionRepository->expects($this->never())->method('revokeAllForUser');

        ($this->handler)(new AnonymizeUserMessage(Uuid::v7()->toRfc4122()));
    }

    #[Test]
    public function itAnonymizesUserAndRevokesSessionsOnSuccess(): void
    {
        $user = new User('original@example.com', 'Real Name');
        $request = new DataErasureRequest($user, 'user request');

        $this->em->expects($this->once())
            ->method('find')
            ->willReturn($request);

        $this->sessionRepository->expects($this->once())
            ->method('revokeAllForUser')
            ->with($user);

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects($this->once())
            ->method('logSecurityEvent')
            ->with('user.anonymized', $user->getId()->toRfc4122());

        $handler = new AnonymizeUserHandler($this->em, $this->sessionRepository, $auditLogger);

        $this->em->expects($this->exactly(2))->method('flush');

        $handler(new AnonymizeUserMessage($request->getId()->toRfc4122()));

        $this->assertSame(DataErasureStatus::Completed, $request->getStatus());
        $this->assertStringEndsWith('@anonymized.invalid', $user->getEmail());
        $this->assertSame('Deleted User', $user->getName());
        $this->assertTrue($user->isDeleted());
    }

    #[Test]
    public function itMarksRequestFailedAndRethrowsOnException(): void
    {
        $user = new User('crash@example.com', 'Crash User');
        $request = new DataErasureRequest($user);

        $this->em->expects($this->once())
            ->method('find')
            ->willReturn($request);

        $this->sessionRepository->expects($this->once())
            ->method('revokeAllForUser')
            ->willThrowException(new RuntimeException('DB exploded'));

        $this->em->expects($this->exactly(2))->method('flush');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DB exploded');

        ($this->handler)(new AnonymizeUserMessage($request->getId()->toRfc4122()));

        $this->assertSame(DataErasureStatus::Failed, $request->getStatus());
        $this->assertSame('DB exploded', $request->getErrorContext()['error'] ?? null);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function itSetsProcessingStatusBeforeAnonymizing(): void
    {
        $user = new User('order@example.com', 'Order Test');
        $request = new DataErasureRequest($user);

        $statusDuringFirstFlush = null;

        $this->em->expects($this->once())
            ->method('find')
            ->willReturn($request);

        $flushCount = 0;
        $this->em->expects($this->exactly(2))
            ->method('flush')
            ->willReturnCallback(static function () use ($request, &$flushCount, &$statusDuringFirstFlush): void {
                ++$flushCount;
                if ($flushCount === 1) {
                    $statusDuringFirstFlush = $request->getStatus();
                }
            });

        $handler = new AnonymizeUserHandler(
            $this->em,
            $this->createStub(UserSessionRepository::class),
            $this->createStub(AuditLogger::class),
        );

        $handler(new AnonymizeUserMessage($request->getId()->toRfc4122()));

        $this->assertSame(DataErasureStatus::Processing, $statusDuringFirstFlush);
        $this->assertSame(DataErasureStatus::Completed, $request->getStatus());
    }
}
